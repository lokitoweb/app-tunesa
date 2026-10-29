<?php

namespace App\Modules\Finance\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\PrivateFile;
use App\Modules\Finance\Models\PettyCashExpense;
use App\Modules\Finance\Models\Supplier;
use App\Modules\Finance\Services\PettyCashImport;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use App\Modules\Core\Rules\SafeUpload;

/** Caja chica (Fcaja_chica): gastos menores con recibo correlativo y comprobante */
class PettyCashWebController extends Controller
{
    private const DISK = TreasuryWebController::VOUCHER_DISK;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? $request->query('month') : now(tenant_timezone())->format('Y-m');
        $box = in_array($request->query('box'), PettyCashExpense::CASH_BOXES, true) ? $request->query('box') : null;
        $start = Carbon::parse("{$month}-01");

        $query = fn () => PettyCashExpense::whereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateTimeString()]) // fin del día: SQLite guarda la fecha con hora
            ->when($box, fn ($q) => $q->where('cash_box', $box));

        return Inertia::render('Finance/Pages/PettyCash/Index', [
            'expenses'   => $query()->orderByDesc('date')->orderByDesc('receipt_number')->paginate(50)->withQueryString()
                ->through(fn (PettyCashExpense $e) => [
                    ...$e->only(['id', 'receipt_number', 'description', 'category', 'beneficiary', 'document_type', 'document_number', 'ruc', 'cash_box']),
                    'amount' => (float) $e->amount, 'date' => $e->date->toDateString(), 'date_label' => $e->date->format('d/m/Y'),
                    'has_voucher' => (bool) $e->voucher_path,
                ]),
            'total'      => (float) $query()->sum('amount'),
            'by_box'     => $query()->toBase()->selectRaw('cash_box, sum(amount) as total')->groupBy('cash_box')->pluck('total', 'cash_box'),
            'filters'    => ['month' => $month, 'box' => $box],
            'options'    => ['document_types' => PettyCashExpense::DOCUMENT_TYPES, 'cash_boxes' => PettyCashExpense::CASH_BOXES],
            'categories' => PettyCashExpense::whereNotNull('category')->distinct()->orderBy('category')->limit(100)->pluck('category'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(current_tenant_id_or_null(), 422, 'Elige una empresa.');
        $data = $this->validated($request);
        $data['voucher_path'] = $request->file('voucher')?->store('finance/' . current_tenant_id_or_null() . '/petty-cash', self::DISK);
        unset($data['voucher']);

        $expense = DB::transaction(fn () => PettyCashExpense::create($data + [
            'receipt_number' => $this->nextReceipt(),
            'created_by'     => $request->user()->id,
        ]));
        $this->audit->log('petty_cash.created', $expense, ['receipt' => $expense->receipt_number, 'amount' => $expense->amount]);

        return back()->with('success', "Gasto registrado con recibo N.º {$expense->receipt_number}.");
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $expense = PettyCashExpense::findOrFail($id);
        $data = $this->validated($request);
        if ($request->hasFile('voucher')) {
            if ($expense->voucher_path) {
                Storage::disk(self::DISK)->delete($expense->voucher_path);
            }
            $data['voucher_path'] = $request->file('voucher')->store("finance/{$expense->tenant_id}/petty-cash", self::DISK);
        }
        unset($data['voucher']);
        $expense->update($data);
        $this->audit->log('petty_cash.updated', $expense, ['receipt' => $expense->receipt_number]);

        return back()->with('success', 'Gasto actualizado.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $expense = PettyCashExpense::findOrFail($id);
        $expense->delete();
        $this->audit->log('petty_cash.deleted', $expense, ['receipt' => $expense->receipt_number, 'amount' => $expense->amount]);

        return back()->with('success', 'Gasto eliminado.');
    }

    public function voucher(int $id)
    {
        $expense = PettyCashExpense::findOrFail($id);

        return PrivateFile::response(self::DISK, $expense->voucher_path);
    }

    /**
     * Importa gastos desde Excel/CSV (columnas del ERP de escritorio). Todo o nada: si una
     * fila no pasa las mismas reglas del registro manual, o el comprobante ya está
     * registrado, no se guarda ninguna y se indica la fila.
     */
    public function import(Request $request): RedirectResponse
    {
        [$rows, $invalid] = $this->checked($request);
        if ($invalid) {
            // Inertia envía un mensaje por campo; se listan las primeras 20 filas con error
            throw ValidationException::withMessages(['file' => implode("\n", array_slice($invalid, 0, 20))]);
        }

        // sisgere Fcaja_chica: un RUC que no está se consulta en SUNAT (Migo) y se registra como proveedor
        $found = $this->suppliersFromSunat(array_unique(array_filter(array_map(fn ($r) => $r['ruc'] && ! $r['beneficiary'] ? $r['ruc'] : null, $rows))));

        [$first, $last] = DB::transaction(function () use ($rows, $request, $found) {
            $next = $this->nextReceipt();
            $first = $next;
            foreach ($found as $ruc => $data) {
                $found[$ruc] = Supplier::withTrashed()->where('ruc', $ruc)->value('name')
                    ?? Supplier::create(['ruc' => $ruc, 'name' => $data['name'], 'address' => mb_substr($data['address'], 0, 100) ?: null,
                        'city' => $data['city'] ? mb_substr($data['city'], 0, 50) : null, 'is_active' => true])->name;
            }
            foreach ($rows as $row) {
                $row['beneficiary'] ??= isset($found[$row['ruc']]) ? Str::limit($found[$row['ruc']], 150, '') : null;
                PettyCashExpense::create($row + ['receipt_number' => $next++, 'created_by' => $request->user()->id]);
            }

            return [$first, $next - 1];
        });
        $this->audit->log('petty_cash.imported', null, ['rows' => count($rows), 'receipts' => "{$first}-{$last}", 'cash_box' => $request->input('cash_box')]);

        return back()->with('success', count($rows) . " gastos importados (recibos N.º {$first} al {$last}).");
    }

    /**
     * Fcaja_chica › Importar Archivo: la lista antes de procesar, con # Registros, Total, Válidos y No válidos.
     * El .NET contaba válidos y no válidos recién al procesar; aquí se ven antes y no se guarda nada.
     */
    public function preview(Request $request): JsonResponse
    {
        [$rows, $invalid] = $this->checked($request);

        return response()->json([
            'count'   => count($rows),
            'total'   => round(array_sum(array_map(fn ($r) => is_numeric($r['amount']) ? (float) $r['amount'] : 0, $rows)), 2),
            'valid'   => count($rows) - count($invalid),
            'invalid' => count($invalid),
            'errors'  => array_values(array_slice($invalid, 0, 50)),
            'rows'    => array_map(fn ($line, $r) => [
                'line' => $line, 'date' => $r['date'], 'document' => trim("{$r['document_type']} {$r['document_number']}"), 'ruc' => $r['ruc'],
                'beneficiary' => $r['beneficiary'], 'description' => $r['description'], 'category' => $r['category'],
                'amount' => is_numeric($r['amount']) ? round((float) $r['amount'], 2) : null, 'ok' => ! isset($invalid[$line]),
            ], array_keys(array_slice($rows, 0, 200, true)), array_slice($rows, 0, 200, true)),
        ]);
    }

    /**
     * Lee el archivo y revisa cada fila con las mismas reglas del registro manual y sin comprobantes repetidos.
     *
     * @return array{0: array<int, array>, 1: array<int, string>} filas por N.º de fila del Excel y error por fila
     */
    private function checked(Request $request): array
    {
        abort_unless(current_tenant_id_or_null(), 422, 'Elige una empresa.');
        $request->validate([
            'file'     => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120'],
            'cash_box' => ['required', Rule::in(PettyCashExpense::CASH_BOXES)],
        ], ['file.mimes' => 'Sube la plantilla en Excel (.xlsx, .xls) o CSV.'], ['file' => 'el archivo', 'cash_box' => 'la caja']);

        try {
            $rows = PettyCashImport::rows($request->file('file')->getRealPath());
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'No se pudo leer el archivo. Usa la plantilla.']);
        }
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'El archivo no tiene gastos.']);
        }
        if (count($rows) > PettyCashImport::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'Máximo ' . PettyCashImport::MAX_ROWS . ' filas por archivo.']);
        }

        [$rules, $messages, $attributes] = self::rules();
        unset($rules['voucher']);
        $errors = [];
        $key = fn ($r) => Str::upper("{$r['document_type']}|{$r['document_number']}|{$r['ruc']}");
        // Comprobantes ya registrados en la empresa, en una sola consulta
        $seen = PettyCashExpense::whereIn('document_number', array_filter(array_column($rows, 'document_number')))
            ->get(['document_type', 'document_number', 'ruc'])->mapWithKeys(fn ($e) => [$key($e) => true])->all();
        foreach ($rows as $line => &$row) {
            $row['cash_box'] = $request->input('cash_box');
            $validator = Validator::make($row, $rules, $messages, $attributes);
            if ($row['currency'] !== 'PEN') {
                $errors[$line] = "Fila {$line}: caja chica es en soles (moneda «{$row['currency']}»).";
            } elseif ($validator->fails()) {
                $errors[$line] = "Fila {$line}: " . $validator->errors()->first();
            } elseif ($row['document_number']) {
                // Sin duplicados: el mismo comprobante no se registra dos veces
                if (isset($seen[$key($row)])) {
                    $errors[$line] = "Fila {$line}: el comprobante {$row['document_type']} {$row['document_number']} ya está registrado.";
                }
                $seen[$key($row)] = true;
            }
            unset($row['currency']);
        }
        unset($row);

        return [$rows, $errors];
    }

    public function template(): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'caja') . '.xlsx';
        PettyCashImport::template($path);

        return response()->download($path, 'plantilla-caja-chica.xlsx')->deleteFileAfterSend();
    }

    /**
     * Razón social de RUC nuevos en SUNAT (Migo de la empresa), antes de la transacción para no esperar la red con
     * la base bloqueada. Sin Migo configurado o si no responde, no hay alta: el gasto se importa igual.
     *
     * @return array<string, array{name: string, address: string, city: ?string}>
     */
    private function suppliersFromSunat(array $rucs): array
    {
        $migo = new \App\Modules\Integrations\Services\MigoLookup(\App\Modules\Core\Models\TenantSetting::forTenant(app(\App\Modules\Core\Services\TenantContext::class)->getOrNull()));
        if (! $rucs || ! $migo->enabled()) {
            return [];
        }
        $found = [];
        foreach (array_slice(array_values($rucs), 0, 50) as $ruc) { // ponytail: tope de 50 consultas por archivo
            if ($data = rescue(fn () => $migo->ruc($ruc), null, report: false)) {
                $found[$ruc] = $data;
            }
        }

        return $found;
    }

    /** Siguiente recibo correlativo de la empresa (llamar dentro de una transacción) */
    private function nextReceipt(): int
    {
        return (int) PettyCashExpense::withTrashed()->lockForUpdate()->max('receipt_number') + 1;
    }

    private function validated(Request $request): array
    {
        return $request->validate(...self::rules());
    }

    /** Reglas del gasto: las mismas para el registro manual y para cada fila importada */
    private static function rules(): array
    {
        return [[
            'date'            => ['required', 'date', 'before_or_equal:today'],
            'description'     => ['required', 'string', 'max:250'],
            'category'        => ['nullable', 'string', 'max:100'],
            'beneficiary'     => ['nullable', 'string', 'max:150'],
            'document_type'   => ['required', Rule::in(PettyCashExpense::DOCUMENT_TYPES)],
            'document_number' => ['nullable', 'string', 'max:50'],
            'ruc'             => ['nullable', 'digits:11'],
            'amount'          => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'cash_box'        => ['required', Rule::in(PettyCashExpense::CASH_BOXES)],
            'voucher'         => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120', new SafeUpload],
        ], [
            'date.before_or_equal'   => 'La fecha no puede ser futura.',
            'amount.min'             => 'El monto debe ser mayor a cero.',
            'voucher.mimes'          => 'El comprobante debe ser PDF o imagen.',
            'voucher.max'            => 'El comprobante pesa más de 5 MB.',
        ], ['date' => 'la fecha', 'description' => 'el concepto', 'amount' => 'el monto', 'document_type' => 'el tipo de comprobante', 'cash_box' => 'la caja']];
    }
}
