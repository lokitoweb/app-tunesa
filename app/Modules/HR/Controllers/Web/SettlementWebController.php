<?php

namespace App\Modules\HR\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Finance\Models\Requisition;
use App\Modules\Finance\Models\Signatory;
use App\Modules\Finance\Services\PayrollPaymentIssuer;
use App\Modules\ModuleRegistry;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\HR\Models\DocumentTemplate;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\HR\Models\EmployeeSettlement;
use App\Modules\HR\Models\LaborRegime;
use App\Modules\HR\Models\PensionRate;
use App\Modules\HR\Services\OvertimeAverages;
use App\Modules\HR\Services\SettlementCalculator;
use App\Modules\HR\Services\VacationBalance;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cese del colaborador y su liquidación de beneficios sociales. Todo parte de lo que ya está en el
 * sistema (ficha, régimen, asistencia, saldo de vacaciones, tasas de pensión) y RR. HH. lo ajusta
 * antes de registrar; lo registrado queda congelado y se reimprime igual.
 */
class SettlementWebController extends Controller
{
    public function __construct(
        private readonly SettlementCalculator $calculator,
        private readonly OvertimeAverages $overtime,
        private readonly VacationBalance $vacations,
        private readonly AuditLogger $audit,
    ) {}

    public function create(Request $request, int $id): Response
    {
        $employee = $this->employee($id);
        $input = $this->resolve($request->query(), $employee);
        $calculable = $employee->hire_date && $input['termination_date'] >= $employee->hire_date->toDateString();

        return Inertia::render('HR/Pages/Settlements/Create', [
            'employee' => [
                'id' => $employee->id, 'name' => $employee->full_name, 'document' => $employee->document_number, 'position' => $employee->position,
                'hire_date' => $employee->hire_date?->toDateString(), 'status' => $employee->status, 'base_salary' => (float) $employee->base_salary,
            ],
            'regime'      => $this->regime($employee)?->only(['code', 'name', ...LaborRegime::PARAMS]),
            'input'       => $input,
            'result'      => $calculable ? $this->calculate($employee, $input) : null,
            'reasons'     => EmployeeSettlement::REASONS,
            'pensions'    => PensionRate::catalog((int) $employee->tenant_id)->where('is_active', true)->map->only(['id', 'name'])->values(),
            'signatories' => $this->signatories(),
            'familyAmount' => $this->familyAmount($employee),
            'history'     => $this->history($employee, $request->user()),
        ]);
    }

    public function store(Request $request, int $id): RedirectResponse
    {
        $employee = $this->employee($id);
        abort_unless($employee->hire_date, 422, 'Registra la fecha de ingreso antes de calcular la liquidación.');
        $money = ['nullable', 'numeric', 'min:0', 'max:9999999'];
        $request->validate([
            'termination_date'        => ['required', 'date', 'after_or_equal:' . $employee->hire_date->toDateString()],
            'reason'                  => ['required', Rule::in(array_keys(EmployeeSettlement::REASONS))],
            'salary'                  => ['required', 'numeric', 'min:0', 'max:9999999'],
            'family'                  => ['boolean'],
            'overtime'                => ['boolean'],
            'ot_cts'                  => $money,
            'ot_vacation'             => $money,
            'ot_gratification'        => $money,
            'other_computable'        => $money,
            'pending_days'            => ['required', 'integer', 'min:0', 'max:999'],
            'indemnity'               => ['boolean'],
            'pension_rate_id'         => ['nullable', 'integer', Rule::exists('pension_rates', 'id')->where('tenant_id', $employee->tenant_id)],
            'signatory_id'            => ['nullable', 'integer', Rule::exists('signatories', 'id')->where('tenant_id', $employee->tenant_id)],
            'other_incomes'           => ['array', 'max:10'],
            'other_incomes.*.label'   => ['required', 'string', 'max:80'],
            'other_incomes.*.amount'  => ['required', 'numeric', 'min:0', 'max:9999999'],
            'other_discounts'         => ['array', 'max:10'],
            'other_discounts.*.label' => ['required', 'string', 'max:80'],
            'other_discounts.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'notes'                   => ['nullable', 'string', 'max:1000'],
        ], [], ['termination_date' => 'la fecha de cese', 'reason' => 'el motivo', 'salary' => 'la remuneración', 'pending_days' => 'los días pendientes']);

        $input = $this->resolve($request->all(), $employee);
        $result = $this->calculate($employee, $input);
        $regime = $this->regime($employee);
        $signatory = $input['signatory_id'] ? Signatory::with('user:id,first_name,last_name,document_number')->find($input['signatory_id']) : null;
        $end = CarbonImmutable::parse($input['termination_date']);

        $settlement = DB::transaction(function () use ($employee, $regime, $input, $result, $end, $request, $signatory) {
            $settlement = EmployeeSettlement::create([
                'tenant_id' => $employee->tenant_id, 'user_id' => $employee->id, 'labor_regime' => $regime?->code,
                'termination_date' => $end, 'reason' => $input['reason'], 'notes' => $request->input('notes'),
                'data' => ['input' => $input, 'regime' => $regime?->only(['code', 'name', ...LaborRegime::PARAMS]),
                    'signatory' => $signatory ? ['name' => $signatory->user?->full_name, 'document' => $signatory->user?->document_number, 'position' => $signatory->position] : null,
                    'employee' => ['branch' => $employee->primaryBranch->first()?->name, 'department' => $employee->department]] + $result,
                'total' => $result['total'], 'created_by' => $request->user()->id,
            ]);
            // El cese rige desde su fecha: asistencia y planilla ya lo respetan; ese día deja de entrar
            $this->audit->updateWithAudit('employee.terminated', $employee, ['termination_date' => $end->toDateString()]
                + ($end->lte(today()) ? ['status' => 'inactive'] : []), ['name' => $employee->full_name, 'total' => $result['total']]);

            return $settlement;
        });

        return redirect()->route('settlements.create', $employee->id)
            ->with('success', "Cese registrado. Imprime la liquidación N.° {$settlement->id} desde «Liquidaciones registradas».");
    }

    /** El neto va a Tesorería como orden de pago «Liquidación»; vence a las 48 horas del cese (plazo de ley) */
    public function treasury(Request $request, int $id, PayrollPaymentIssuer $issuer): RedirectResponse
    {
        $settlement = EmployeeSettlement::with('user.profile', 'user.primaryBranch', 'tenant')->findOrFail($id);
        $employee = $settlement->user;
        abort_unless($request->user()->canManage($employee), 403);
        abort_unless(ModuleRegistry::enabledFor($settlement->tenant, 'Finance'), 404);
        $period =$settlement->termination_date->format('Y-m');
        $error = match (true) {
            $settlement->total <= 0                              => 'El neto de la liquidación es cero.',
            $issuer->exists($employee, $period, 'LIQUIDACION')   => "{$employee->full_name} ya tiene su liquidación en Tesorería.",
            default                                              => $issuer->accountError($employee, 'LIQUIDACION'),
        };
        if ($error) {
            return back()->with('error', rtrim(ucfirst($error), '.') . '.');
        }
        $due = max($settlement->termination_date->copy()->addDays(2)->toDateString(), today()->toDateString());
        $payment = DB::transaction(fn () => $issuer->issue($settlement->tenant, $employee, $settlement->total, $period, 'LIQUIDACION', $due, $request->user()));
        $this->audit->log('settlement.sent_to_treasury', $settlement, ['code' => $payment->code(), 'total' => $settlement->total]);

        return back()->with('success', "Liquidación enviada a Tesorería ({$payment->code()}), vence el " . date('d/m/Y', strtotime($due)) . '.');
    }

    /** Documentos del cese que se imprimen (?doc=): uno solo o el paquete completo */
    public const DOCUMENTS = [
        'liquidation'        => 'Liquidación de beneficios sociales',
        'work_certificate'   => 'Certificado de trabajo',
        'cts_release_letter' => 'Carta de liberación de CTS',
    ];

    public function print(Request $request, int $id): View
    {
        $settlement = EmployeeSettlement::with(['user.profile', 'creator:id,first_name,last_name', 'tenant'])->findOrFail($id);
        $employee = $settlement->user;
        abort_unless($request->user()->canManage($employee), 403);
        $doc = (string) $request->query('doc', 'liquidation');
        abort_unless($doc === 'all' || isset(self::DOCUMENTS[$doc]), 404);
        // La carta de CTS solo si tiene cuenta CTS en su legajo
        $hasCts = (bool) $employee->profile?->cts_account_number;
        $docs = $doc === 'all' ? array_keys(array_filter(self::DOCUMENTS, fn ($k) => $k !== 'cts_release_letter' || $hasCts, ARRAY_FILTER_USE_KEY)) : [$doc];
        abort_if($docs === ['cts_release_letter'] && ! $hasCts, 422, "{$employee->full_name} no tiene cuenta CTS en su legajo.");

        $settings = TenantSetting::forTenant($settlement->tenant);
        $company = ['name' => $settlement->tenant?->company_name, 'ruc' => $settlement->tenant?->ruc, 'address' => $settings->address ?: $settlement->tenant?->address,
            'logo' => $settlement->tenant?->logo_path ? asset('storage/' . $settlement->tenant->logo_path) : null];
        // El número de cuenta completo, como en la ficha: solo con permiso y queda en la bitácora
        $fullAccount = in_array('cts_release_letter', $docs, true) && $request->user()->hasPermission('profiles.view_sensitive');
        if ($fullAccount) {
            $this->audit->log('payment_info.revealed', $employee, ['document' => 'cts_release_letter']);
        }
        $sig = $settlement->data['signatory'] ?? null;
        $vars = [
            'empresa' => $company['name'], 'ruc' => $company['ruc'], 'direccion' => $company['address'],
            'representante' => $sig['name'] ?? null, 'representante_cargo' => $sig['position'] ?? null, 'representante_dni' => $sig['document'] ?? null,
            'colaborador' => $employee->full_name, 'documento' => trim(strtoupper((string) $employee->document_type) . ' ' . $employee->document_number), 'cargo' => $employee->position,
            'fecha_ingreso' => $employee->hire_date ? DocumentTemplate::longDate($employee->hire_date) : null,
            'fecha_cese' => DocumentTemplate::longDate($settlement->termination_date), 'motivo' => EmployeeSettlement::REASONS[$settlement->reason] ?? $settlement->reason,
            'banco_cts' => $employee->profile?->cts_bank_name,
            'cuenta_cts' => $fullAccount ? $employee->profile?->cts_account_number : EmployeeProfile::mask($employee->profile?->cts_account_number),
            'fecha_documento' => DocumentTemplate::longDate($settlement->termination_date),
        ];
        $keys = in_array('liquidation', $docs, true) ? ['settlement_intro', 'settlement_closing', ...array_diff($docs, ['liquidation'])] : $docs;

        return view('settlements.print', [
            's' => $settlement, 'reasons' => EmployeeSettlement::REASONS, 'docs' => $docs, 'company' => $company,
            'pageTitle' => $doc === 'all' ? 'Documentos del cese' : self::DOCUMENTS[$doc],
            'texts' => collect($keys)->mapWithKeys(fn ($k) => [$k => DocumentTemplate::render((int) $settlement->tenant_id, $k, $vars)])->all(),
            'signature' => isset($settlement->data['input']['signatory_id']) ? Signatory::find($settlement->data['input']['signatory_id'])?->signatureDataUri() : null,
        ]);
    }

    /**
     * Valores de la liquidación: lo que envió RR. HH. y, si falta, lo que dice el sistema (sueldo de la
     * ficha, asignación familiar, promedio de horas extras de la asistencia, saldo de vacaciones, AFP/ONP).
     */
    private function resolve(array $raw, User $employee): array
    {
        $has = fn (string $k) => array_key_exists($k, $raw) && $raw[$k] !== null && $raw[$k] !== '';
        $bool = fn (string $k, bool $default) => $has($k) ? filter_var($raw[$k], FILTER_VALIDATE_BOOLEAN) : $default;
        $num = fn (string $k, float $default) => $has($k) ? max(0, round((float) $raw[$k], 2)) : $default;
        $date = $has('termination_date') && strtotime((string) $raw['termination_date']) ? date('Y-m-d', strtotime((string) $raw['termination_date'])) : null;
        $end = CarbonImmutable::parse($date ?? ($employee->termination_date ?? today())->toDateString());
        $reason = array_key_exists((string) ($raw['reason'] ?? ''), EmployeeSettlement::REASONS) ? $raw['reason'] : 'resignation';
        $salary = $num('salary', (float) $employee->base_salary);
        $averages = $employee->hire_date ? $this->overtime->for($employee, $end, $salary) : ['cts' => 0.0, 'vacation' => 0.0, 'gratification' => 0.0, 'months' => []];
        $overtime = $bool('overtime', $averages['cts'] > 0 || $averages['gratification'] > 0);
        $family = $bool('family', (bool) $employee->profile?->family_allowance);
        $lines = fn (string $k) => collect(is_array($raw[$k] ?? null) ? $raw[$k] : [])
            ->filter(fn ($o) => is_array($o) && trim((string) ($o['label'] ?? '')) !== '' && (float) ($o['amount'] ?? 0) > 0)
            ->map(fn ($o) => ['label' => trim((string) $o['label']), 'amount' => round((float) $o['amount'], 2)])->take(10)->values()->all();
        $pensions = PensionRate::catalog((int) $employee->tenant_id);
        $pensionId = array_key_exists('pension_rate_id', $raw)
            ? $pensions->firstWhere('id', (int) $raw['pension_rate_id'])?->id
            : PensionRate::forProfile((int) $employee->tenant_id, $employee->profile)?->id;
        $signatories = $this->signatories();
        $signatoryId = array_key_exists('signatory_id', $raw) ? $signatories->firstWhere('id', (int) $raw['signatory_id'])['id'] ?? null : $signatories->first()['id'] ?? null;

        return [
            'termination_date' => $end->toDateString(),
            'reason'           => $reason,
            'salary'           => $salary,
            'family'           => $family,
            'family_allowance' => $family ? $this->familyAmount($employee) : 0.0,
            'overtime'         => $overtime,
            'ot_cts'           => $overtime ? $num('ot_cts', $averages['cts']) : 0.0,
            'ot_vacation'      => $overtime ? $num('ot_vacation', $averages['vacation']) : 0.0,
            'ot_gratification' => $overtime ? $num('ot_gratification', $averages['gratification']) : 0.0,
            'ot_months'        => $averages['months'],
            'other_computable' => $num('other_computable', 0.0),
            'pending_days'     => $has('pending_days') ? max(0, (int) $raw['pending_days'])
                : max(0, (int) ($employee->hire_date ? ($this->vacations->for($employee, $end)['balance'] ?? 0) : 0)),
            'indemnity'        => $bool('indemnity', $reason === 'arbitrary_dismissal'),
            'pension_rate_id'  => $pensionId,
            'signatory_id'     => $signatoryId,
            'other_incomes'    => $lines('other_incomes'),
            'other_discounts'  => $lines('other_discounts'),
        ];
    }

    /** Liquidaciones registradas y, con Tesorería activa, si ya se envió el pago */
    private function history(User $employee, User $actor): \Illuminate\Support\Collection
    {
        $treasury = ModuleRegistry::enabledFor($employee->tenant, 'Finance') && $actor->hasPermission('payroll.payments');
        $payments = $treasury ? Requisition::where('employee_id', $employee->id)->where('payroll_concept', 'LIQUIDACION')->get()->keyBy('payroll_period') : collect();
        // Última boleta: la del mes de cese, si ya se subió en Boletas de pago
        $payslips = ModuleRegistry::enabledFor($employee->tenant, 'Payroll') && $actor->hasPermission('payslips.manage')
            ? Payslip::where('user_id', $employee->id)->get(['id', 'period_year', 'period_month'])->keyBy(fn ($p) => sprintf('%04d-%02d', $p->period_year, $p->period_month))
            : collect();
        $hasCts = (bool) $employee->profile?->cts_account_number;

        return EmployeeSettlement::where('user_id', $employee->id)->latest('id')->get(['id', 'termination_date', 'reason', 'total', 'created_at'])
            ->map(fn ($s) => ['id' => $s->id, 'date' => $s->termination_date->format('d/m/Y'), 'reason' => EmployeeSettlement::REASONS[$s->reason] ?? $s->reason, 'total' => $s->total,
                'documents' => array_filter(self::DOCUMENTS, fn ($k) => $k !== 'cts_release_letter' || $hasCts, ARRAY_FILTER_USE_KEY),
                'payslip' => ($slip = $payslips->get($s->termination_date->format('Y-m'))) ? "/payslips/{$slip->id}/view" : null,
                'payment' => ($p = $payments->get($s->termination_date->format('Y-m'))) ? "En Tesorería: {$p->code()}" . ($p->stage === 'paid' ? ' · pagado' : '') : null,
                'can_pay' => $treasury && ! $payments->has($s->termination_date->format('Y-m')) && $s->total > 0]);
    }

    private function calculate(User $employee, array $input): array
    {
        $pension = $input['pension_rate_id'] ? PensionRate::withoutGlobalScopes()->find($input['pension_rate_id']) : null;

        return $this->calculator->calculate($employee, $this->regime($employee), $pension, $input);
    }

    /** Asignación familiar: % de la remuneración mínima de la empresa (Regímenes laborales) */
    private function familyAmount(User $employee): float
    {
        $s = TenantSetting::forTenant($employee->tenant);

        return round((float) $s->minimum_wage * (float) $s->family_allowance_pct / 100, 2);
    }

    /** Apoderados activos de la empresa (Compras y tesorería › Apoderados): firman la liquidación */
    private function signatories(): \Illuminate\Support\Collection
    {
        return Signatory::with('user:id,first_name,last_name')->where('is_active', true)->orderByDesc('can_authorize')->orderBy('id')->get()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->user?->full_name, 'position' => $s->position]);
    }

    private function employee(int $id): User
    {
        $employee = User::forTenant(current_tenant_id_or_null())->with(['profile', 'primaryBranch'])->findOrFail($id);
        abort_unless(request()->user()->canManage($employee), 403);

        return $employee;
    }

    private function regime(User $employee): ?LaborRegime
    {
        $code = $employee->profile?->labor_regime;

        return $code ? LaborRegime::catalog((int) $employee->tenant_id)->firstWhere('code', $code) : null;
    }
}
