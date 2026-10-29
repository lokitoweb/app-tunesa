<?php

namespace App\Modules\Finance\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\PrivateFile;
use App\Modules\Finance\Models\CompanyBankAccount;
use App\Modules\Finance\Models\PaymentCancellation;
use App\Modules\Finance\Models\PaymentInstallment;
use App\Modules\Finance\Models\Requisition;
use App\Modules\Finance\Models\Signatory;
use App\Modules\Finance\Notifications\SupplierDocument;
use App\Modules\Finance\Services\FinanceDocumentPdf;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use App\Modules\Core\Rules\SafeUpload;

/**
 * Tesorería (FrmReqProg / FrmRegpagos): las cuotas de las órdenes emitidas
 * se programan, la jefatura autoriza y tesorería paga (total o amortización)
 * desde una cuenta de la empresa, con número de operación y voucher.
 */
class TreasuryWebController extends Controller
{
    public const VOUCHER_DISK = 'private';

    /** Pestañas del tablero: qué cuotas muestra cada una */
    public const TABS = [
        'to_schedule' => 'Por programar',
        'scheduled'   => 'Programadas',
        'authorized'  => 'Autorizadas',
        'overdue'     => 'Vencidas',
        'paid'        => 'Pagadas',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'to_schedule';
        $today = now(tenant_timezone())->toDateString();

        $filters = $this->filters($request);
        $installments = $this->listQuery($tab, $filters, $today)
            ->paginate(30)->withQueryString()
            ->through(fn (PaymentInstallment $p) => $this->row($p));

        $base = fn () => PaymentInstallment::query();
        $pending = fn () => $base()->where('payment_installments.status', 'pending');
        // Saldos por moneda de la orden: soles y dólares nunca se suman entre sí
        $byCurrency = fn ($query, string $expr) => $query->join('requisitions', 'requisitions.id', '=', 'payment_installments.requisition_id')->whereNull('requisitions.deleted_at')
            ->toBase()->selectRaw("coalesce(requisitions.currency, 'PEN') as currency, sum({$expr}) as total")->groupBy('currency')
            ->pluck('total', 'currency')->map(fn ($v) => round((float) $v, 2));
        $owed = 'payment_installments.amount - payment_installments.paid_amount';

        return Inertia::render('Finance/Pages/Treasury/Index', [
            'installments' => $installments,
            'tabs'         => self::TABS,
            'counts'       => collect(self::TABS)->map(fn ($_, $key) => $this->tab($base(), $key, $today)->count()),
            'kpis'         => [
                'overdue'   => $byCurrency($pending()->whereDate('payment_installments.due_date', '<', $today), $owed),
                'week'      => $byCurrency($pending()->whereBetween('payment_installments.due_date', [$today, now(tenant_timezone())->addDays(7)->endOfDay()->toDateTimeString()]), $owed),
                'pending'   => $byCurrency($pending(), $owed),
                'paid_month' => $byCurrency(PaymentCancellation::where('payment_cancellations.paid_at', '>=', now(tenant_timezone())->startOfMonth()->utc())
                    ->join('payment_installments', 'payment_installments.id', '=', 'payment_cancellations.payment_installment_id'), 'payment_cancellations.amount'),
            ],
            'filters'      => ['tab' => $tab] + $filters,
            'accounts'     => CompanyBankAccount::orderByDesc('is_active')->orderBy('bank')->get()
                ->map(fn (CompanyBankAccount $a) => $a->only(['id', 'bank', 'account_number', 'currency', 'alias', 'is_active']) + ['label' => $a->label()]),
            'currencies'   => Requisition::CURRENCIES,
            // Pago directo (solo quien paga lo registra)
            'direct'       => $request->user()->hasPermission('treasury.pay') ? [
                'suppliers'       => \App\Modules\Finance\Models\Supplier::where('is_active', true)->orderBy('name')->limit(1000)->get(['id', 'name', 'ruc']),
                'document_types'  => self::DOCUMENT_TYPES,
                'payment_methods' => Requisition::PAYMENT_METHODS,
                'detraction_reasons' => RequisitionWebController::options()['detraction_reasons'],
                'cash_flow_concepts' => config('finance.cash_flow_concepts'),
            ] : null,
            'can'          => [
                'pay'       => $request->user()->hasPermission('treasury.pay'),
                'authorize' => Signatory::canAuthorize($request->user()),
            ],
            'today'        => $today,
        ]);
    }

    /** La grilla de la pestaña en Excel, con la misma búsqueda (FrmReqProg › Exportar a Excel) */
    public function export(Request $request): BinaryFileResponse
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'to_schedule';
        $rows = $this->listQuery($tab, $this->filters($request), now(tenant_timezone())->toDateString())
            ->limit(5000)->get()->map(function (PaymentInstallment $p) {
                $r = $p->requisition;
                $row = $this->row($p);

                return [$r?->orderNumber(), $row['supplier']['name'] ?? null, $row['supplier']['ruc'] ?? null, $r?->detail, "{$p->installment} de {$r?->installments}",
                    $p->due_date?->format('d/m/Y'), $p->scheduled_for?->format('d/m/Y'), $r?->currency, (float) $p->amount, (float) $p->paid_amount,
                    round((float) $p->amount - (float) $p->paid_amount, 2), $p->authorized_at ? 'Sí' : 'No', $p->status === 'paid' ? 'Pagada' : 'Pendiente'];
            });
        $path = tempnam(sys_get_temp_dir(), 'tes') . '.xlsx';
        \App\Modules\Core\Support\SpreadsheetRows::export($path, self::TABS[$tab], ['Orden', 'Proveedor / beneficiario', 'RUC / DNI', 'Detalle', 'Cuota',
            'Vence', 'Programada', 'Moneda', 'Monto', 'Pagado', 'Saldo', 'Autorizada', 'Estado'], $rows);

        return response()->download($path, 'tesoreria-' . str_replace('_', '-', $tab) . '-' . now()->format('Ymd') . '.xlsx')->deleteFileAfterSend();
    }

    /** Filtros de FrmRegpagos: Buscar Prov., Desde / Hasta y "Mostrar Planilla ?" */
    private function filters(Request $request): array
    {
        $date = fn ($k) => ($v = $request->query($k)) && \DateTime::createFromFormat('!Y-m-d', (string) $v) ? (string) $v : null;

        return ['search' => trim((string) $request->query('search')), 'from' => $date('from'), 'to' => $date('to'), 'payroll' => $request->query('payroll') !== '0'];
    }

    private function listQuery(string $tab, array $filters, string $today): Builder
    {
        $search = $filters['search'];
        // Desde / Hasta sobre la fecha que manda en cada pestaña: pagadas por pago, programadas por programación, el resto por vencimiento
        $dateColumn = match ($tab) { 'paid' => 'paid_at', 'scheduled', 'authorized' => 'scheduled_for', default => 'due_date' };

        return $this->tab(PaymentInstallment::query(), $tab, $today)
            ->when($filters['from'], fn ($q, $d) => $q->whereDate($dateColumn, '>=', $d))
            ->when($filters['to'], fn ($q, $d) => $q->whereDate($dateColumn, '<=', $d))
            ->when(! $filters['payroll'], fn ($q) => $q->whereHas('requisition', fn ($r) => $r->whereNull('payroll_concept')))
            ->with(['requisition:id,series,number,code,stage,cash_flow_concept,invoice_number,invoice_path,detail,supplier_id,employee_id,payroll_concept,payroll_period,is_direct_payment,currency,installments,purchase_order_number,service_order_number,has_detraction,detraction_reason,detraction_number,detraction_amount,detraction_percent,amount',
                'requisition.supplier:id,name,ruc', 'requisition.supplier.bankAccounts:id,supplier_id,bank,account_number,account_type',
                'requisition.selectedQuotation:id,requisition_id,bank,account_number',
                'requisition.employee:id,first_name,last_name,document_number', 'requisition.employee.profile',
                'cancellations.companyAccount:id,bank,account_number,currency,alias'])
            ->when($search !== '', fn ($q) => $q->whereHas('requisition', fn ($r) => $r->where('detail', 'like', "%{$search}%")
                ->orWhere('purchase_order_number', 'like', "%{$search}%")->orWhere('service_order_number', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")
                ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$search}%")->orWhere('ruc', 'like', "{$search}%"))
                // Planilla: el "proveedor" es el colaborador (nombre o DNI)
                ->orWhereHas('employee', fn ($e) => $e->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")->orWhere('document_number', 'like', "{$search}%"))))
            ->orderBy($tab === 'paid' ? 'paid_at' : 'due_date', $tab === 'paid' ? 'desc' : 'asc');
    }

    /** Fecha en que se pagará (Modificar_PROGRAMACION) */
    public function schedule(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['scheduled_for' => ['required', 'date']], ['required' => 'Indica la fecha de pago.', 'date' => 'La fecha no es válida.']);
        $p = $this->pending($id);
        abort_unless($p->requisition?->orderNumber(), 422, 'La orden de pago necesita una OC/OS válida.');
        $p->update(['scheduled_for' => $data['scheduled_for'], 'scheduled_by' => $request->user()->full_name]);
        $this->audit->log('installment.scheduled', $p->requisition, ['installment' => $p->installment, 'scheduled_for' => $data['scheduled_for']]);

        return back()->with('success', 'Cuota programada.');
    }

    /** Visto bueno de jefatura antes de pagar (validar_pago_final) */
    public function authorizePayment(Request $request, int $id): RedirectResponse
    {
        $p = $this->pending($id);
        $p->authorizeBy($request->user());
        $this->audit->log('installment.authorized', $p->requisition, ['installment' => $p->installment]);

        return back()->with('success', 'Pago autorizado: tesorería ya puede pagarlo.');
    }

    /** Autoriza varias cuotas marcadas a la vez (FrmRegpagos); misma regla que una por una */
    public function authorizeMany(Request $request): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['integer', 'distinct']], ['ids.required' => 'Marca al menos una cuota.']);
        $rows = PaymentInstallment::with('requisition')->whereKey($data['ids'])->get();
        abort_if($rows->count() !== count($data['ids']), 404);
        abort_unless(Signatory::canAuthorize($request->user()), 403, 'Solo la persona designada por la empresa autoriza pagos.');

        DB::transaction(function () use ($rows, $request) {
            foreach ($rows as $p) {
                $p->authorizeBy($request->user());
                $this->audit->log('installment.authorized', $p->requisition, ['installment' => $p->installment]);
            }
        });

        return back()->with('success', "{$rows->count()} pagos autorizados: tesorería ya puede pagarlos.");
    }

    /** Orden de pago (OP) en PDF con la firma de quien la autorizó */
    public function paymentOrderPdf(int $id): HttpResponse
    {
        $p = PaymentInstallment::with('requisition')->findOrFail($id);
        abort_unless($p->scheduled_for, 422, 'La cuota aún no está programada.');

        return response(FinanceDocumentPdf::paymentOrder($p), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="OP-' . $p->requisition->orderNumber() . "-{$p->installment}.pdf\"",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Pago total o amortización con cuenta de origen y voucher (Tblcancelaciones_crear) */
    public function pay(Request $request, int $id): RedirectResponse
    {
        $p = $this->pending($id);
        abort_unless($p->authorized_at, 422, 'El pago necesita la autorización de la persona designada.');
        if ($p->requisition->isPayroll()) {
            // Se paga solo a la cuenta del legajo verificada por RRHH (el colaborador puede editarla en la app)
            abort_unless($p->requisition->payrollAccount()['verified'], 422, 'La cuenta del legajo del colaborador no está verificada por RRHH.');
        } else {
            abort_unless($p->requisition->invoice_path, 422, 'Registra la factura del proveedor (PDF) antes de pagar.');
        }
        // Segregación de funciones: quien autoriza no registra el mismo pago
        abort_if($p->authorized_by_id === $request->user()->id, 403, 'Autorizaste este pago: otra persona debe registrarlo.');
        $remaining = round((float) $p->amount - (float) $p->paid_amount, 2);

        $data = $request->validate([
            'paid_at'                 => ['required', 'date', 'before_or_equal:today'],
            'amount'                  => ['required', 'numeric', 'min:0.01', "max:{$remaining}"],
            'company_bank_account_id' => ['required', Rule::exists('company_bank_accounts', 'id')->where('tenant_id', $p->tenant_id)->where('is_active', true)],
            'operation_number'        => ['required', 'string', 'max:50'],
            // Sin PDF/foto del depósito no se registra el pago
            'voucher'                 => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120', new SafeUpload],
        ], [
            'amount.max'              => "El monto supera el saldo de la cuota ({$remaining}).",
            'amount.min'              => 'El monto debe ser mayor a cero.',
            'paid_at.before_or_equal' => 'La fecha de pago no puede ser futura.',
            'exists'                  => 'Elige una cuenta de origen activa.',
            'voucher.required'        => 'Adjunta el voucher del depósito o abono.',
            'voucher.mimes'           => 'El voucher debe ser PDF o imagen.',
            'voucher.max'             => 'El voucher pesa más de 5 MB.',
        ], ['paid_at' => 'la fecha de pago', 'amount' => 'el monto', 'company_bank_account_id' => 'la cuenta de origen', 'operation_number' => 'el número de operación']);

        $path = $request->file('voucher')?->store("finance/{$p->tenant_id}/vouchers", self::VOUCHER_DISK);
        $account = CompanyBankAccount::findOrFail($data['company_bank_account_id']);

        try {
            $paidOff = $this->registerPayment($p, $data, $account, $path, $request);
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk(self::VOUCHER_DISK)->delete($path); // sin pago no queda voucher huérfano
            }
            throw $e;
        }
        $this->audit->log('installment.paid', $p->requisition, ['installment' => $p->installment, 'amount' => $data['amount'], 'operation' => $data['operation_number']]);

        // Comprobante al proveedor o al colaborador (SMTP de la empresa) con el voucher adjunto
        $r = $p->requisition->load(['supplier', 'tenant', 'employee', 'user']);
        // Aviso en la campana a quien pidió el requerimiento (si no es quien pagó)
        if (! $r->isPayroll() && $r->user && $r->user_id !== $request->user()->id) {
            rescue(fn () => $r->user->notify(new \App\Modules\Finance\Notifications\RequisitionPaid($p, (float) $data['amount'])), report: true);
        }
        $email = $r->isPayroll() ? $r->employee?->email : $r->supplier?->email;
        if ($email) {
            $status = $paidOff ? 'Pago cancelado' : 'Pago amortizado';
            $balance = round((float) $p->fresh()->amount - (float) $p->fresh()->paid_amount, 2);
            rescue(fn () => Notification::route('mail', $email)->notify(new SupplierDocument(
                $r->tenant, "{$status} · {$r->orderNumber()}",
                array_filter([
                    "Registramos un pago de " . FinanceDocumentPdf::money($r->currency, $data['amount']) . " por la {$r->orderNumber()} (cuota {$p->installment}), operación {$data['operation_number']}.",
                    $paidOff ? null : 'Saldo pendiente: ' . FinanceDocumentPdf::money($r->currency, $balance) . '. Se reprogramará para su pago.',
                ]),
                Storage::disk(self::VOUCHER_DISK)->get($path), 'voucher-' . $data['operation_number'] . '.' . pathinfo($path, PATHINFO_EXTENSION),
                greeting: $r->isPayroll() ? "Hola, {$r->employee->first_name}:" : 'Estimado proveedor:',
            )), report: true);
        }

        return back()->with('success', $paidOff ? 'Pago cancelado: la cuota quedó pagada.' : 'Pago amortizado: el saldo volvió a "Por programar" para una nueva orden de pago.');
    }

    private function registerPayment(PaymentInstallment $p, array $data, CompanyBankAccount $account, ?string $path, Request $request): bool
    {
        return DB::transaction(function () use ($p, $data, $account, $path, $request) {
            $p = PaymentInstallment::lockForUpdate()->findOrFail($p->id);
            $amount = round((float) $data['amount'], 2);
            // Con la cuota bloqueada se revisa otra vez: dos envíos a la vez (doble clic o dos tesoreros)
            // no la pagan dos veces ni la pasan del monto
            $remaining = round((float) $p->amount - (float) $p->paid_amount, 2);
            if ($p->status === 'paid' || ! $p->authorized_at || $amount > $remaining + 0.004) {
                throw ValidationException::withMessages(['amount' => $p->status === 'paid' || $remaining <= 0
                    ? 'Esta cuota ya se pagó. Actualiza la pantalla.'
                    : ($p->authorized_at ? "El monto supera el saldo de la cuota ({$remaining})." : 'El pago necesita la autorización de la persona designada.')]);
            }
            $p->cancellations()->create([
                'tenant_id' => $p->tenant_id, 'company_bank_account_id' => $account->id, 'paid_at' => $data['paid_at'], 'amount' => $amount,
                'bank' => $account->bank, 'account_number' => $account->account_number, 'operation_number' => $data['operation_number'],
                'voucher_path' => $path, 'is_amortization' => $amount < $remaining,
                'registered_by' => $request->user()->full_name, 'authorized_by' => $p->authorized_by_id ? optional(\App\Modules\Core\Models\User::find($p->authorized_by_id))->full_name : null,
            ]);

            $paidOff = round((float) $p->paid_amount + $amount, 2) >= (float) $p->amount;
            // Bucle de amortización: el saldo vuelve a la cola de OP (por programar y autorizar otra vez)
            $p->update(['paid_amount' => round((float) $p->paid_amount + $amount, 2)] + ($paidOff
                ? ['status' => 'paid', 'paid_at' => now()]
                : ['scheduled_for' => null, 'scheduled_by' => null, 'authorized_by_id' => null, 'authorized_at' => null]));

            // Todas las cuotas pagadas: el requerimiento queda Pagado
            if ($paidOff && ! $p->requisition->paymentInstallments()->where('status', '!=', 'paid')->exists()) {
                $p->requisition->update(['stage' => 'paid']);
                // Vino de un costo fijo: su cuota queda pagada con la misma cuenta y operación
                if ($p->requisition->fixed_cost_id) {
                    \App\Modules\Finance\Models\FixedCost::whereKey($p->requisition->fixed_cost_id)->update([
                        'paid_at' => $data['paid_at'], 'company_bank_account_id' => $account->id, 'operation_number' => $data['operation_number'],
                    ]);
                }
            }

            return $paidOff;
        });
    }

    public function voucher(int $id)
    {
        $c = PaymentCancellation::findOrFail($id);

        return PrivateFile::response(self::VOUCHER_DISK, $c->voucher_path);
    }

    /**
     * Pago directo de Tesorería (FrmReqProg › Registrar): un PG-… sin pasar por logística, con su
     * documento asociado. Entra a la cola como cualquier orden: se programa, se autoriza y se paga.
     * Marcado como anticipo, una vez pagado se descuenta de la siguiente OC/OS del proveedor.
     */
    public function directPayment(Request $request): RedirectResponse
    {
        $tenant = \App\Modules\Core\Models\Tenant::find(current_tenant_id_or_null());
        abort_unless($tenant, 422, 'Elige una empresa.');
        $data = $request->validate(...$this->directRules($tenant->id));

        $data['detraction_reason'] = ($data['has_detraction'] ?? false) ? $data['detraction_reason'] : null;
        $total = round((float) $data['amount'], 2);

        $path = $request->file('file')?->store("finance/{$tenant->id}/invoices", self::VOUCHER_DISK);
        try {
            $r = \App\Modules\Finance\Services\DirectPayments::create($tenant, $request->user(), $data, $path);
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk(self::VOUCHER_DISK)->delete($path);
            }
            throw $e;
        }
        $this->audit->log('treasury.direct_payment', $r, ['code' => $r->code(), 'amount' => $total, 'advance' => (bool) ($data['is_advance'] ?? false)]);

        return back()->with('success', "Pago directo {$r->code()} registrado: queda por programar." . ($path ? '' : ' Adjunta el PDF del documento antes de pagarlo.'));
    }

    /** Columnas de la carga de gastos (sisgere FRMBANCOS: fecha, medio, serie, número, proveedor, glosa, monto) */
    private const DIRECT_IMPORT_COLUMNS = [
        'Fecha'      => ['date', ['fecha']],
        'Medio'      => ['payment_method', ['medio']],
        'Tipo'       => ['document_type', ['tipo']],
        'Serie'      => ['series', ['serie']],
        'Número'     => ['number', ['numero', 'nro']],
        'RUC'        => ['ruc', ['ruc', 'proveedor']],
        'Glosa'      => ['detail', ['glosa', 'detalle', 'descrip']],
        'Monto'      => ['amount', ['monto', 'importe']],
    ];

    /**
     * Carga de gastos por pagar desde Excel (sisgere Tesorería › Bancos › Procesar carga): cada fila es un
     * pago directo con las mismas reglas del formulario. Todo o nada; el proveedor se busca por RUC en la empresa.
     */
    public function importDirect(Request $request): RedirectResponse
    {
        $tenant = \App\Modules\Core\Models\Tenant::find(current_tenant_id_or_null());
        abort_unless($tenant, 422, 'Elige una empresa.');
        $request->validate([
            'file'     => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:2048'],
            'currency' => ['required', Rule::in(array_keys(Requisition::CURRENCIES))],
        ], ['file.required' => 'Elige el archivo Excel.', 'file.mimes' => 'El archivo debe ser Excel (.xlsx, .xls) o CSV.']);

        $suppliers = \App\Modules\Finance\Models\Supplier::where('is_active', true)->whereNotNull('ruc')->pluck('id', 'ruc');
        // "FACT", "Factura", "BOLETA"… al código del tipo; lo desconocido es factura
        $types = collect(self::DOCUMENT_TYPES)->flatMap(fn ($label, $code) => [$code => $code, mb_strtoupper($label) => $code])->all()
            + ['FACTURA' => 'FACT', 'BOLETA' => 'BBVV', 'RECIBO' => 'RRHH'];
        [$rules, $messages, $attributes] = $this->directRules($tenant->id);
        unset($rules['file']);
        $rows = [];
        $errors = [];
        foreach (\App\Modules\Core\Support\SpreadsheetRows::read($request->file('file')->getRealPath(), self::DIRECT_IMPORT_COLUMNS) as $line => $r) {
            $date = \App\Modules\Finance\Services\PettyCashImport::date($r['date'] ?? null);
            $method = strtoupper(trim((string) ($r['payment_method'] ?? '')));
            $data = [
                'supplier_id'     => $suppliers[preg_replace('/\D/', '', (string) ($r['ruc'] ?? ''))] ?? null,
                'detail'          => trim((string) ($r['detail'] ?? '')),
                'document_type'   => $types[strtoupper(trim((string) ($r['document_type'] ?? '')))] ?? 'FACT',
                'document_number' => trim(implode('-', array_filter([trim((string) ($r['series'] ?? '')), trim((string) ($r['number'] ?? ''))]))) ?: null,
                'document_date'   => $date, 'due_date' => $date, 'currency' => $request->input('currency'),
                'amount'          => \App\Modules\Finance\Services\PettyCashImport::amount($r['amount'] ?? null),
                'payment_method'  => in_array($method, Requisition::PAYMENT_METHODS, true) ? $method : 'TRANSFERENCIA',
            ];
            $v = \Illuminate\Support\Facades\Validator::make($data, $rules, $messages, $attributes);
            if ($v->fails()) {
                $errors[] = "Fila {$line}: " . ($data['supplier_id'] ? $v->errors()->first() : 'no hay un proveedor activo con ese RUC');
                if (count($errors) >= 15) {
                    break;
                }
                continue;
            }
            $rows[] = $v->validated();
        }
        if ($errors || ! $rows || count($rows) > 500) {
            throw ValidationException::withMessages(['file' => $errors ? implode(' ', $errors) : ($rows ? 'Máximo 500 gastos por carga.' : 'El archivo no tiene gastos.')]);
        }

        $codes = DB::transaction(fn () => collect($rows)->map(fn ($d) => \App\Modules\Finance\Services\DirectPayments::create($tenant, $request->user(), $d)->code()));
        $this->audit->log('treasury.direct_import', null, ['rows' => $codes->count(), 'currency' => $request->input('currency')]);

        return back()->with('success', "{$codes->count()} gasto(s) cargado(s) como pago directo: quedan por programar.");
    }

    /** Reglas del pago directo (formulario y carga desde Excel) */
    private function directRules(int $tenantId): array
    {
        return [[
            'supplier_id'       => ['required', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)->where('is_active', true)->whereNull('deleted_at')],
            'detail'            => ['required', 'string', 'max:1000'],
            'document_type'     => ['required', Rule::in(array_keys(self::DOCUMENT_TYPES))],
            // Orden de pago sin número: el sistema le da su correlativo OP-… (sisgere: requerimiento_mostrar_nroODP)
            'document_number'   => ['required_unless:document_type,ORDP', 'nullable', 'string', 'max:40'],
            'document_date'     => ['required', 'date', 'before_or_equal:today'],
            'file'              => ['nullable', 'file', 'mimes:pdf', 'max:5120', SafeUpload::pdf()],
            'currency'          => ['required', Rule::in(array_keys(Requisition::CURRENCIES))],
            'amount'            => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'due_date'          => ['required', 'date'],
            'payment_method'    => ['required', Rule::in(Requisition::PAYMENT_METHODS)],
            'has_detraction'    => ['boolean'],
            'detraction_reason' => ['nullable', 'required_if_accepted:has_detraction', Rule::in(array_keys(config('finance.detraction_reasons')))],
            'is_advance'        => ['boolean'],
            'cash_flow_concept' => ['nullable', Rule::in(Requisition::cashFlowConcepts())],
        ], [
            'document_date.before_or_equal' => 'La fecha del documento no puede ser futura.', 'file.mimes' => 'El documento debe ser PDF.',
            'detraction_reason.required_if_accepted' => 'Elige el motivo de la detracción.', 'exists' => 'Elige un proveedor activo.',
        ], ['supplier_id' => 'el proveedor', 'detail' => 'el detalle', 'document_type' => 'el tipo de documento', 'document_number' => 'el número de documento',
            'document_date' => 'la fecha del documento', 'amount' => 'el monto', 'due_date' => 'el vencimiento', 'detraction_reason' => 'el motivo de detracción']];
    }

    /**
     * Corrige un pago directo aún sin pagos (sisgere corregir_requerimiento01/02): detalle, moneda y
     * monto. Cambiar el monto rehace la cuota y quita la autorización (hay que autorizar de nuevo).
     */
    public function updateDirectPayment(Request $request, int $id): RedirectResponse
    {
        $r = Requisition::where('is_direct_payment', true)->findOrFail($id);
        $p = $r->paymentInstallments()->sole();
        abort_if($r->stage !== 'ordered' || (float) $p->paid_amount > 0 || $p->advance_applied_to_id, 422, 'El pago directo ya tiene pagos registrados: no se puede corregir.');
        $data = $request->validate([
            'detail'   => ['required', 'string', 'max:1000'],
            'currency' => ['required', Rule::in(array_keys(Requisition::CURRENCIES))],
            'amount'   => ['required', 'numeric', 'min:0.01', 'max:999999999'],
        ], [], ['detail' => 'el detalle', 'amount' => 'el monto']);

        $total = round((float) $data['amount'], 2);
        $detraction = $r->detraction_reason ? \App\Modules\Finance\Services\OrderPricing::detraction($total, (int) $r->detraction_percent) : null;
        $payable = round($total - (float) $detraction, 2);
        abort_if($payable <= 0, 422, 'La detracción no puede ser igual o mayor que el monto.');
        $before = $r->only(['detail', 'currency', 'amount']);
        $changedAmount = abs($payable - (float) $p->amount) > 0.004 || $data['currency'] !== $r->currency;

        DB::transaction(function () use ($r, $p, $data, $total, $detraction, $payable, $changedAmount) {
            // Con la cuota bloqueada se revisa otra vez: un pago registrado en paralelo no se pisa
            $locked = PaymentInstallment::whereKey($p->id)->lockForUpdate()->first();
            abort_if((float) $locked->paid_amount > 0 || $locked->advance_applied_to_id, 422, 'El pago directo ya tiene pagos registrados: no se puede corregir.');

            $r->update(['detail' => $data['detail'], 'currency' => $data['currency'], 'amount' => $total, 'amount_payable' => $payable, 'detraction_amount' => $detraction]);
            $p->update(['amount' => $payable] + ($changedAmount ? ['authorized_by_id' => null, 'authorized_at' => null] : []));
        });
        $this->audit->log('treasury.direct_payment_corrected', $r, ['code' => $r->code(), 'before' => $before, 'after' => $data]);

        return back()->with('success', $changedAmount && $p->wasChanged('authorized_at') ? 'Pago directo corregido: vuelve a necesitar autorización.' : 'Pago directo corregido.');
    }

    /**
     * Concepto del flujo de efectivo de una orden (sisgere FrmRegpagos › flujo_requerimiento): se
     * elige o corrige desde Tesorería, también ya pagada (es clasificación, no cambia montos).
     */
    public function updateCashFlow(Request $request, int $id): RedirectResponse
    {
        $r = Requisition::findOrFail($id);
        abort_unless($r->orderNumber(), 422, 'El requerimiento aún no tiene orden de compra, servicio o pago.');
        $data = $request->validate([
            'cash_flow_concept' => ['required', Rule::in(Requisition::cashFlowConcepts())],
        ], ['cash_flow_concept.required' => 'Elige el concepto del flujo de efectivo.', 'cash_flow_concept.in' => 'Elige un concepto de la lista.']);

        $before = $r->cash_flow_concept;
        $r->update($data);
        $this->audit->log('treasury.cash_flow_changed', $r, ['code' => $r->code(), 'before' => $before, 'after' => $data['cash_flow_concept']]);

        return back()->with('success', 'Concepto del flujo de efectivo actualizado.');
    }

    /**
     * Corrige el monto de una OC/OS sin pagos (sisgere actualizar_monto_requerim: borra las cuotas y
     * las vuelve a crear). Se recalculan detracción y neto con el mismo motivo y anticipo, se rehacen
     * las cuotas (mismo número, desde el primer vencimiento) y vuelven a necesitar autorización.
     */
    public function correctOrderAmount(Request $request, int $id): RedirectResponse
    {
        $r = Requisition::where('stage', 'ordered')->where('is_direct_payment', false)->whereNull('payroll_concept')->findOrFail($id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'reason' => ['required', 'string', 'max:500'],
        ], [], ['amount' => 'el monto', 'reason' => 'el motivo']);

        $total = round((float) $data['amount'], 2);
        $detraction = $r->detraction_reason ? \App\Modules\Finance\Services\OrderPricing::detraction($total, (int) $r->detraction_percent) : null;
        $payable = round($total - (float) $detraction - (float) $r->advance_amount, 2);
        if ($payable <= 0) {
            throw ValidationException::withMessages(['amount' => 'Con la detracción y el anticipo aplicado, el monto no deja nada por pagar.']);
        }
        $before = (float) $r->amount;

        DB::transaction(function () use ($r, $total, $detraction, $payable) {
            // Con las cuotas bloqueadas: un pago registrado en paralelo impide corregir
            $installments = $r->paymentInstallments()->lockForUpdate()->orderBy('installment')->get();
            if ($installments->sum(fn ($p) => (float) $p->paid_amount) > 0 || $installments->contains(fn ($p) => $p->status === 'paid')) {
                throw ValidationException::withMessages(['amount' => 'La orden ya tiene pagos registrados: no se puede corregir el monto.']);
            }
            $first = $installments->first();
            $count = max(1, $installments->count());
            $interval = $count > 1 ? max(1, (int) $first->due_date->diffInDays($installments[1]->due_date)) : 30;
            $schedule = \App\Modules\Finance\Services\OrderPricing::installments($payable, $count, $first?->due_date?->toDateString() ?? now()->toDateString(), $interval);

            $r->update(['amount' => $total, 'amount_payable' => $payable, 'detraction_amount' => $detraction]);
            $r->paymentInstallments()->delete();
            foreach ($schedule as $i => $c) {
                $r->paymentInstallments()->create([
                    'tenant_id' => $r->tenant_id, 'installment' => $i + 1, 'due_date' => $c['due_date'], 'amount' => $c['amount'], 'status' => 'pending',
                    'payment_method' => $first?->payment_method ?? $r->payment_method, 'registered_by' => $first?->registered_by,
                ]);
            }
        });
        $this->audit->log('treasury.order_amount_corrected', $r, ['code' => $r->code(), 'order' => $r->orderNumber(), 'before' => $before, 'after' => $total, 'reason' => $data['reason']]);

        return back()->with('success', 'Monto corregido: las cuotas se rehicieron y vuelven a necesitar programación y autorización.');
    }

    /**
     * Corrige un pago registrado (sisgere Modificar_cuenta_origen y eliminar_foto): cuenta de origen,
     * n.º de operación y voucher. El monto no se corrige: para eso se anula y registra de nuevo.
     */
    public function correctPayment(Request $request, int $id): RedirectResponse
    {
        $c = PaymentCancellation::findOrFail($id);
        $data = $request->validate([
            'company_bank_account_id' => ['required', Rule::exists('company_bank_accounts', 'id')->where('tenant_id', $c->tenant_id)->where('is_active', true)],
            'operation_number'        => ['required', 'string', 'max:50'],
            'voucher'                 => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120', new SafeUpload],
        ], ['exists' => 'Elige una cuenta de origen activa.', 'voucher.mimes' => 'El voucher debe ser PDF o imagen.', 'voucher.max' => 'El voucher pesa más de 5 MB.'],
            ['company_bank_account_id' => 'la cuenta de origen', 'operation_number' => 'el número de operación']);

        $account = CompanyBankAccount::findOrFail($data['company_bank_account_id']);
        $before = $c->only(['bank', 'account_number', 'operation_number']);
        $old = $c->voucher_path;
        $update = ['company_bank_account_id' => $account->id, 'bank' => $account->bank, 'account_number' => $account->account_number, 'operation_number' => $data['operation_number']];
        if ($request->hasFile('voucher')) {
            $update['voucher_path'] = $request->file('voucher')->store("finance/{$c->tenant_id}/vouchers", self::VOUCHER_DISK);
        }
        $c->update($update);
        if (isset($update['voucher_path']) && $old) {
            Storage::disk(self::VOUCHER_DISK)->delete($old); // el voucher reemplazado no queda huérfano
        }
        $this->audit->log('payment.corrected', $c->installment?->requisition, ['payment' => $c->id, 'before' => $before, 'after' => collect($update)->except('voucher_path')->all(), 'voucher_replaced' => isset($update['voucher_path'])]);

        return back()->with('success', 'Pago corregido.');
    }

    /** Documento asociado del pago directo (sisgere FrmReqProg: ORDP, BBVV, FACT, NPED, RRHH, VALE, OTRO) */
    public const DOCUMENT_TYPES = [
        'FACT' => 'Factura', 'BBVV' => 'Boleta de venta', 'RRHH' => 'Recibo por honorarios', 'ORDP' => 'Orden de pago',
        'NPED' => 'Nota de pedido', 'VALE' => 'Vale', 'OTRO' => 'Otro',
    ];

    /** Detracción del requerimiento (corregir_detraccion) */
    public function detraction(Request $request, int $id): RedirectResponse
    {
        $r = Requisition::findOrFail($id);
        abort_unless($r->has_detraction, 422, 'La orden no está afecta a detracción.');
        // Con motivo, el % y el monto se fijaron al emitir la orden (las cuotas van sobre el neto): solo la constancia
        $fixed = $r->detraction_reason !== null;
        $data = $request->validate([
            'detraction_number'  => ['required', 'string', 'max:100'],
            'detraction_percent' => [Rule::excludeIf($fixed), 'required', 'integer', 'min:1', 'max:100'],
            'detraction_amount'  => [Rule::excludeIf($fixed), 'required', 'numeric', 'min:0.01', 'max:' . (float) $r->amount],
        ], [],
            ['detraction_number' => 'la constancia', 'detraction_percent' => 'el porcentaje', 'detraction_amount' => 'el monto']);

        $r->update($data);
        $this->audit->log('requisition.detraction', $r, ['code' => $r->code()] + $data);

        return back()->with('success', 'Detracción registrada.');
    }

    // ---------------------------------------------------------------- cuentas de origen

    public function storeAccount(Request $request): RedirectResponse
    {
        abort_unless(current_tenant_id_or_null(), 422, 'Elige una empresa.');
        CompanyBankAccount::create($this->validatedAccount($request));

        return back()->with('success', 'Cuenta de origen registrada.');
    }

    public function updateAccount(Request $request, int $id): RedirectResponse
    {
        $account = CompanyBankAccount::findOrFail($id);
        $account->update($this->validatedAccount($request, $account->id));

        return back()->with('success', 'Cuenta de origen actualizada.');
    }

    // ------------------------------------------------------------------ privado

    private function tab(Builder $q, string $tab, string $today): Builder
    {
        return match ($tab) {
            'to_schedule' => $q->where('status', 'pending')->whereNull('scheduled_for'),
            'scheduled'   => $q->where('status', 'pending')->whereNotNull('scheduled_for')->whereNull('authorized_at'),
            'authorized'  => $q->where('status', 'pending')->whereNotNull('authorized_at'),
            'overdue'     => $q->where('status', 'pending')->whereDate('due_date', '<', $today),
            'paid'        => $q->where('status', 'paid'),
        };
    }

    private function pending(int $id): PaymentInstallment
    {
        $p = PaymentInstallment::with('requisition')->findOrFail($id);
        abort_unless($p->status === 'pending', 422, 'La cuota ya está pagada.');

        return $p;
    }

    private function row(PaymentInstallment $p): array
    {
        $r = $p->requisition;

        return [
            'id'            => $p->id,
            'installment'   => $p->installment,
            'of'            => $r?->installments,
            'due_date'      => $p->due_date?->format('d/m/Y'),
            'due_iso'       => $p->due_date?->toDateString(),
            'scheduled_for' => $p->scheduled_for?->toDateString(),
            'scheduled_by'  => $p->scheduled_by,
            'authorized'    => (bool) $p->authorized_at,
            'authorized_at' => $p->authorized_at?->timezone(tenant_timezone())->format('d/m/Y H:i'),
            'amount'        => (float) $p->amount,
            'paid_amount'   => (float) $p->paid_amount,
            'balance'       => round((float) $p->amount - (float) $p->paid_amount, 2),
            'status'        => $p->status,
            'payment_method' => $p->payment_method,
            'is_advance'    => $p->is_advance,
            'requisition'   => $r ? [
                'id' => $r->id, 'code' => $r->code(), 'detail' => $r->detail, 'order' => $r->orderNumber(), 'currency' => $r->currency,
                'amount' => (float) $r->amount, 'has_detraction' => $r->has_detraction, 'is_direct' => (bool) $r->is_direct_payment,
                'cash_flow_concept' => $r->cash_flow_concept, 'is_order' => ! $r->is_direct_payment && ! $r->isPayroll() && $r->stage === 'ordered',
                // Pago de RRHH: no lleva factura (la boleta es el sustento)
                'has_invoice' => $r->isPayroll() || $r->invoice_path, 'is_payroll' => $r->isPayroll(), 'invoice_number' => $r->invoice_number,
                'detraction' => $r->only(['detraction_number', 'detraction_amount', 'detraction_percent', 'detraction_reason']),
            ] : null,
            'supplier'      => $r?->isPayroll() ? $this->payrollPayee($r) : ($r?->supplier ? [
                'name' => $r->supplier->name, 'ruc' => $r->supplier->ruc,
                'accounts' => $r->supplierAccounts(),
            ] : null),
            'payments'      => $p->cancellations->map(fn (PaymentCancellation $c) => [
                'id' => $c->id, 'paid_at' => $c->paid_at?->format('d/m/Y'), 'amount' => (float) $c->amount, 'operation' => $c->operation_number,
                'account' => $c->companyAccount?->label() ?? trim("{$c->bank} {$c->account_number}"), 'has_voucher' => (bool) $c->voucher_path,
                'registered_by' => $c->registered_by, 'account_id' => $c->company_bank_account_id,
            ]),
        ];
    }

    /** Colaborador y cuenta de su legajo (completa solo con permiso de ver cuentas) */
    private function payrollPayee(Requisition $r): array
    {
        $account = $r->payrollAccount();
        $number = \App\Modules\HR\Models\EmployeeProfile::accountFor($account['number'], request()->user());

        return [
            'name' => $r->employee?->full_name, 'ruc' => 'DNI ' . $r->employee?->document_number,
            'accounts' => $number ? [trim("{$account['bank']} {$number}") . ($account['verified'] ? '' : ' (sin verificar)')] : ['Sin cuenta en el legajo'],
        ];
    }

    private function validatedAccount(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'bank'           => ['required', 'string', 'max:50'],
            'account_number' => ['required', 'string', 'max:100',
                Rule::unique('company_bank_accounts')->where('tenant_id', current_tenant_id_or_null())->where('bank', $request->input('bank'))->ignore($ignoreId)],
            'currency'       => ['required', Rule::in(array_keys(Requisition::CURRENCIES))],
            'alias'          => ['nullable', 'string', 'max:60'],
            'is_active'      => ['boolean'],
        ], ['account_number.unique' => 'Esa cuenta ya está registrada.'], ['bank' => 'el banco', 'account_number' => 'el número de cuenta']);
    }
}
