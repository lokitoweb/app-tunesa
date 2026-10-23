<?php

namespace App\Modules\Finance\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Finance\Models\PettyCashExpense;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

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
            'receipt_number' => (int) PettyCashExpense::withTrashed()->lockForUpdate()->max('receipt_number') + 1,
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
        abort_unless($expense->voucher_path && Storage::disk(self::DISK)->exists($expense->voucher_path), 404);

        return response()->file(Storage::disk(self::DISK)->path($expense->voucher_path), ['Cache-Control' => 'private, no-store'])
            ->setPrivate(); // BinaryFileResponse se marca public por defecto
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'date'            => ['required', 'date', 'before_or_equal:today'],
            'description'     => ['required', 'string', 'max:250'],
            'category'        => ['nullable', 'string', 'max:100'],
            'beneficiary'     => ['nullable', 'string', 'max:150'],
            'document_type'   => ['required', Rule::in(PettyCashExpense::DOCUMENT_TYPES)],
            'document_number' => ['nullable', 'string', 'max:50'],
            'ruc'             => ['nullable', 'digits:11'],
            'amount'          => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'cash_box'        => ['required', Rule::in(PettyCashExpense::CASH_BOXES)],
            'voucher'         => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'required'               => 'Falta :attribute.',
            'date.before_or_equal'   => 'La fecha no puede ser futura.',
            'digits'                 => 'El RUC tiene 11 dígitos.',
            'min'                    => ':Attribute debe ser mayor a cero.',
            'voucher.mimes'          => 'El comprobante debe ser PDF o imagen.',
            'voucher.max'            => 'El comprobante pesa más de 5 MB.',
        ], ['date' => 'la fecha', 'description' => 'el concepto', 'amount' => 'el monto', 'document_type' => 'el tipo de comprobante', 'cash_box' => 'la caja']);
    }
}
