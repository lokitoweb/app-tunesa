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
use Inertia\Inertia;
use Inertia\Response;
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
        $search = trim((string) $request->query('search'));
        $today = now(tenant_timezone())->toDateString();

        $installments = $this->tab(PaymentInstallment::query(), $tab, $today)
            ->with(['requisition:id,series,number,code,invoice_number,invoice_path,detail,supplier_id,employee_id,payroll_concept,payroll_period,currency,installments,purchase_order_number,service_order_number,has_detraction,detraction_number,detraction_amount,detraction_percent,amount',
                'requisition.supplier:id,name,ruc', 'requisition.supplier.bankAccounts:id,supplier_id,bank,account_number,account_type',
                'requisition.employee:id,first_name,last_name,document_number', 'requisition.employee.profile',
                'cancellations.companyAccount:id,bank,account_number,currency,alias'])
            ->when($search !== '', fn ($q) => $q->whereHas('requisition', fn ($r) => $r->where('detail', 'like', "%{$search}%")
                ->orWhere('purchase_order_number', 'like', "%{$search}%")->orWhere('service_order_number', 'like', "%{$search}%")
                ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$search}%")->orWhere('ruc', 'like', "{$search}%"))))
            ->orderBy($tab === 'paid' ? 'paid_at' : 'due_date', $tab === 'paid' ? 'desc' : 'asc')
            ->paginate(30)->withQueryString()
            ->through(fn (PaymentInstallment $p) => $this->row($p));

        $base = fn () => PaymentInstallment::query();
        $pending = fn () => $base()->where('status', 'pending');

        return Inertia::render('Finance/Pages/Treasury/Index', [
            'installments' => $installments,
            'tabs'         => self::TABS,
            'counts'       => collect(self::TABS)->map(fn ($_, $key) => $this->tab($base(), $key, $today)->count()),
            'kpis'         => [
                'overdue'   => (float) $pending()->whereDate('due_date', '<', $today)->sum(DB::raw('amount - paid_amount')),
                'week'      => (float) $pending()->whereBetween('due_date', [$today, now(tenant_timezone())->addDays(7)->endOfDay()->toDateTimeString()])->sum(DB::raw('amount - paid_amount')),
                'pending'   => (float) $pending()->sum(DB::raw('amount - paid_amount')),
                'paid_month' => (float) PaymentCancellation::where('paid_at', '>=', now(tenant_timezone())->startOfMonth()->utc())->sum('amount'),
            ],
            'filters'      => ['tab' => $tab, 'search' => $search],
            'accounts'     => CompanyBankAccount::orderByDesc('is_active')->orderBy('bank')->get()
                ->map(fn (CompanyBankAccount $a) => $a->only(['id', 'bank', 'account_number', 'currency', 'alias', 'is_active']) + ['label' => $a->label()]),
            'currencies'   => Requisition::CURRENCIES,
            'can'          => [
                'pay'       => $request->user()->hasPermission('treasury.pay'),
                'authorize' => Signatory::canAuthorize($request->user()),
            ],
            'today'        => $today,
        ]);
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
            $paidOff = $this->registerPayment($p, $data, $account, $path, $remaining, $request);
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk(self::VOUCHER_DISK)->delete($path); // sin pago no queda voucher huérfano
            }
            throw $e;
        }
        $this->audit->log('installment.paid', $p->requisition, ['installment' => $p->installment, 'amount' => $data['amount'], 'operation' => $data['operation_number']]);

        // Comprobante al proveedor o al colaborador (SMTP de la empresa) con el voucher adjunto
        $r = $p->requisition->load(['supplier', 'tenant', 'employee']);
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

    private function registerPayment(PaymentInstallment $p, array $data, CompanyBankAccount $account, ?string $path, float $remaining, Request $request): bool
    {
        return DB::transaction(function () use ($p, $data, $account, $path, $remaining, $request) {
            $p = PaymentInstallment::lockForUpdate()->findOrFail($p->id);
            $amount = round((float) $data['amount'], 2);
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
            }

            return $paidOff;
        });
    }

    public function voucher(int $id)
    {
        $c = PaymentCancellation::findOrFail($id);

        return PrivateFile::response(self::VOUCHER_DISK, $c->voucher_path);
    }

    /** Detracción del requerimiento (corregir_detraccion) */
    public function detraction(Request $request, int $id): RedirectResponse
    {
        $r = Requisition::findOrFail($id);
        abort_unless($r->has_detraction, 422, 'La orden no está afecta a detracción.');
        $data = $request->validate([
            'detraction_number'  => ['required', 'string', 'max:100'],
            'detraction_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'detraction_amount'  => ['required', 'numeric', 'min:0.01', 'max:' . (float) $r->amount],
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
            'requisition'   => $r ? [
                'id' => $r->id, 'code' => $r->code(), 'detail' => $r->detail, 'order' => $r->orderNumber(), 'currency' => $r->currency,
                'amount' => (float) $r->amount, 'has_detraction' => $r->has_detraction,
                // Pago de RRHH: no lleva factura (la boleta es el sustento)
                'has_invoice' => $r->isPayroll() || $r->invoice_path, 'is_payroll' => $r->isPayroll(),
                'detraction' => $r->only(['detraction_number', 'detraction_amount', 'detraction_percent']),
            ] : null,
            'supplier'      => $r?->isPayroll() ? $this->payrollPayee($r) : ($r?->supplier ? [
                'name' => $r->supplier->name, 'ruc' => $r->supplier->ruc,
                'accounts' => $r->supplier->bankAccounts->map(fn ($a) => "{$a->bank} {$a->account_number}")->values(),
            ] : null),
            'payments'      => $p->cancellations->map(fn (PaymentCancellation $c) => [
                'id' => $c->id, 'paid_at' => $c->paid_at?->format('d/m/Y'), 'amount' => (float) $c->amount, 'operation' => $c->operation_number,
                'account' => $c->companyAccount?->label() ?? trim("{$c->bank} {$c->account_number}"), 'has_voucher' => (bool) $c->voucher_path,
                'registered_by' => $c->registered_by,
            ]),
        ];
    }

    /** Colaborador y cuenta de su legajo (completa solo con permiso de ver cuentas) */
    private function payrollPayee(Requisition $r): array
    {
        $account = $r->payrollAccount();
        $user = request()->user();
        $full = $user->hasPermission('profiles.view_sensitive') || $user->hasPermission('payroll.export');
        $number = $account['number'] ? ($full ? $account['number'] : '••••' . substr($account['number'], -4)) : null;

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
