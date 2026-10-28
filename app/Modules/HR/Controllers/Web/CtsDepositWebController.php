<?php

namespace App\Modules\HR\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Finance\Models\Requisition;
use App\Modules\Finance\Models\Signatory;
use App\Modules\Finance\Services\PayrollPaymentIssuer;
use App\Modules\HR\Models\CtsDeposit;
use App\Modules\HR\Models\DocumentTemplate;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\HR\Models\LaborRegime;
use App\Modules\HR\Services\OvertimeAverages;
use App\Modules\HR\Services\SettlementCalculator;
use App\Modules\ModuleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

/**
 * RR. HH. › Depósitos de CTS (mayo y noviembre): calcula a todo el personal en planilla con la
 * misma base que la liquidación, registra el semestre (congelado), envía cada depósito a
 * Tesorería a la cuenta CTS del legajo e imprime las constancias.
 */
class CtsDepositWebController extends Controller
{
    public function __construct(
        private readonly SettlementCalculator $calculator,
        private readonly OvertimeAverages $overtime,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $tenant = $this->tenant();
        [$year, $month] = $this->period($request);
        [$start, $end, $due] = CtsDeposit::semester($year, $month);
        $overtime = $request->boolean('overtime');
        $rows = $this->rows($tenant, $start, $end, $overtime);
        $treasury = ModuleRegistry::enabledFor($tenant, 'Finance') && $request->user()->hasPermission('payroll.payments');
        $payments = $treasury ? Requisition::where('payroll_concept', 'CTS')->where('payroll_period', $due->format('Y-m'))->get(['id', 'employee_id', 'code', 'series', 'number', 'year', 'stage'])->keyBy('employee_id') : collect();

        return Inertia::render('HR/Pages/CtsDeposits/Index', [
            'period'   => ['year' => $year, 'month' => $month, 'start' => $start->toDateString(), 'end' => $end->toDateString(), 'due' => $due->toDateString(), 'overtime' => $overtime],
            'rows'     => $rows->map(fn ($r) => $r + ['payment' => ($p = $payments->get($r['user_id'])) ? $p->code() . ($p->stage === 'paid' ? ' · pagado' : '') : null])->values(),
            'totals'   => ['people' => $rows->whereNotNull('amount')->count(), 'amount' => round($rows->sum('amount'), 2),
                'registered' => $rows->whereNotNull('deposit_id')->count(), 'sent' => $payments->count()],
            'treasury' => $treasury,
            'signatories' => Signatory::with('user:id,first_name,last_name')->where('is_active', true)->orderByDesc('can_authorize')->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->user?->full_name, 'position' => $s->position])->values(),
        ]);
    }

    /** Registra el semestre de quienes aún no lo tienen (los ya registrados no se tocan) */
    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'], 'month' => ['required', 'in:5,11'], 'overtime' => ['boolean'],
            'signatory_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('signatories', 'id')->where('tenant_id', $tenant->id)],
        ]);
        [$start, $end, $due] = CtsDeposit::semester((int) $data['year'], (int) $data['month']);
        $signatory = ! empty($data['signatory_id']) ? Signatory::with('user:id,first_name,last_name,document_number')->find($data['signatory_id']) : null;
        $pending = $this->rows($tenant, $start, $end, (bool) ($data['overtime'] ?? false))->whereNull('deposit_id')->whereNotNull('amount');

        DB::transaction(function () use ($pending, $tenant, $start, $end, $due, $signatory, $request) {
            foreach ($pending as $row) {
                CtsDeposit::create([
                    'tenant_id' => $tenant->id, 'user_id' => $row['user_id'], 'period_start' => $start, 'period_end' => $end, 'due_date' => $due,
                    'amount' => $row['amount'], 'created_by' => $request->user()->id,
                    'data' => ['calc' => $row['calc'], 'regime' => $row['regime'],
                        'signatory' => $signatory ? ['id' => $signatory->id, 'name' => $signatory->user?->full_name, 'document' => $signatory->user?->document_number, 'position' => $signatory->position] : null],
                ]);
            }
        });
        $this->audit->log('cts.registered', null, ['period' => "{$start->toDateString()} / {$end->toDateString()}", 'people' => $pending->count(), 'total' => round($pending->sum('amount'), 2)]);

        return back()->with('success', $pending->count() . ' depósito(s) de CTS registrados por S/ ' . number_format($pending->sum('amount'), 2) . '.');
    }

    /** Quitar un depósito registrado para recalcularlo (solo si aún no está en Tesorería) */
    public function destroy(int $id): RedirectResponse
    {
        $deposit = CtsDeposit::with('user')->findOrFail($id);
        abort_if(Requisition::withTrashed()->where(['employee_id' => $deposit->user_id, 'payroll_concept' => 'CTS', 'payroll_period' => $deposit->paymentPeriod()])->exists(),
            422, 'Ya está en Tesorería: anula allá el pago antes de recalcularlo.');
        $deposit->delete();
        $this->audit->log('cts.deleted', $deposit->user, ['period' => $deposit->period_start->toDateString(), 'amount' => $deposit->amount]);

        return back()->with('success', "Depósito de {$deposit->user?->full_name} quitado: se volverá a calcular al registrar.");
    }

    /** Envía a Tesorería los depósitos registrados del semestre (a la cuenta CTS verificada) */
    public function treasury(Request $request, PayrollPaymentIssuer $issuer): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless(ModuleRegistry::enabledFor($tenant, 'Finance'), 404);
        [$start, , $due] = CtsDeposit::semester($request->integer('year'), $request->integer('month') === 5 ? 5 : 11);
        $period = $due->format('Y-m');
        $deposits = CtsDeposit::with(['user.profile', 'user.primaryBranch', 'user.assignedBranch'])->whereDate('period_start', $start)->where('amount', '>', 0)->get();
        $skipped = [];
        $sent = DB::transaction(function () use ($deposits, $issuer, $tenant, $period, $due, $request, &$skipped) {
            $n = 0;
            foreach ($deposits as $d) {
                if ($issuer->exists($d->user, $period, 'CTS')) {
                    continue;
                }
                if ($error = $issuer->accountError($d->user, 'CTS')) {
                    $skipped[] = $error;
                    continue;
                }
                $issuer->issue($tenant, $d->user, $d->amount, $period, 'CTS', max($due->toDateString(), today()->toDateString()), $request->user());
                $n++;
            }

            return $n;
        });
        $this->audit->log('cts.sent_to_treasury', null, ['period' => $period, 'sent' => $sent, 'skipped' => count($skipped)]);

        return back()->with($skipped ? 'error' : 'success', "{$sent} depósito(s) enviados a Tesorería."
            . ($skipped ? ' Sin enviar: ' . implode('; ', array_slice($skipped, 0, 5)) . (count($skipped) > 5 ? '…' : '') . '.' : ''));
    }

    /** Constancias: ?id= una, o todas las registradas del semestre (?year=&month=) */
    public function print(Request $request): View
    {
        $tenant = $this->tenant();
        $query = CtsDeposit::with('user.profile')->orderBy('id');
        if ($request->filled('id')) {
            $query->whereKey($request->integer('id'));
        } else {
            [$year, $month] = $this->period($request);
            $query->whereDate('period_start', CtsDeposit::semester($year, $month)[0]);
        }
        $deposits = $query->get()->filter(fn ($d) => $request->user()->canManage($d->user));
        abort_if($deposits->isEmpty(), 404);

        $settings = TenantSetting::forTenant($tenant);
        $company = ['name' => $tenant->company_name, 'ruc' => $tenant->ruc, 'address' => $settings->address ?: $tenant->address,
            'logo' => $tenant->logo_path ? asset('storage/' . $tenant->logo_path) : null];
        // Número de cuenta completo solo con permiso (como en la ficha), y queda en la bitácora
        $full = $request->user()->hasPermission('profiles.view_sensitive');
        if ($full) {
            $this->audit->log('payment_info.revealed', null, ['document' => 'cts_deposit_certificate', 'people' => $deposits->count()]);
        }
        $signatures = [];

        return view('cts.print', ['company' => $company, 'pages' => $deposits->map(function (CtsDeposit $d) use ($tenant, $company, $full, &$signatures) {
            $u = $d->user;
            $sig = $d->data['signatory'] ?? null;
            $account = $u->profile?->cts_account_number;

            return ['deposit' => $d, 'signature' => $sig ? ($signatures[$sig['id']] ??= Signatory::find($sig['id'])?->signatureDataUri()) : null,
                'text' => DocumentTemplate::render($tenant->id, 'cts_deposit_certificate', [
                    'empresa' => $company['name'], 'ruc' => $company['ruc'], 'direccion' => $company['address'],
                    'representante' => $sig['name'] ?? null, 'representante_cargo' => $sig['position'] ?? null, 'representante_dni' => $sig['document'] ?? null,
                    'colaborador' => $u->full_name, 'documento' => trim(strtoupper((string) $u->document_type) . ' ' . $u->document_number), 'cargo' => $u->position,
                    'fecha_ingreso' => $u->hire_date ? DocumentTemplate::longDate($u->hire_date) : null,
                    'banco_cts' => $u->profile?->cts_bank_name, 'cuenta_cts' => $full ? $account : EmployeeProfile::mask($account),
                    'periodo_cts' => DocumentTemplate::longDate($d->period_start) . ' al ' . DocumentTemplate::longDate($d->period_end),
                    'fecha_deposito' => DocumentTemplate::longDate($d->due_date), 'monto_cts' => 'S/ ' . number_format($d->amount, 2),
                    'fecha_documento' => DocumentTemplate::longDate($d->due_date),
                ])];
        })->values()]);
    }

    /**
     * Personal en planilla que trabajó en el semestre, con su depósito registrado o el cálculo de hoy.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(Tenant $tenant, CarbonImmutable $start, CarbonImmutable $end, bool $overtime): Collection
    {
        $settings = TenantSetting::forTenant($tenant);
        $regimes = LaborRegime::catalog($tenant->id)->keyBy('code');
        $registered = CtsDeposit::whereDate('period_start', $start)->get()->keyBy('user_id');
        $people = User::query()->regime('planilla')->whereNotIn('role', ['super_admin'])->whereNotNull('hire_date')
            ->whereDate('hire_date', '<=', $end)
            // Quien cesó dentro del semestre cobra esa CTS en su liquidación (CTS trunca), no aquí
            ->where(fn ($q) => $q->whereNull('termination_date')->orWhereDate('termination_date', '>', $end))
            ->with('profile')->orderBy('last_name')->orderBy('first_name')->get();

        // ponytail: una consulta de asistencia por persona para el promedio de horas extras; agrupar si hay miles
        return $people->map(function (User $u) use ($registered, $regimes, $settings, $start, $end, $overtime) {
            $regime = $regimes->get((string) $u->profile?->labor_regime);
            $row = ['user_id' => $u->id, 'name' => $u->full_name, 'dni' => $u->document_number, 'regime' => $regime?->name,
                'cts_account' => (bool) $u->profile?->cts_account_number];
            if ($deposit = $registered->get($u->id)) {
                return $row + ['amount' => $deposit->amount, 'calc' => $deposit->data['calc'] ?? null, 'note' => null, 'deposit_id' => $deposit->id];
            }
            $salary = (float) $u->base_salary;
            $averages = $overtime ? $this->overtime->for($u, $end, $salary) : ['cts' => 0.0, 'gratification' => 0.0];
            $calc = $this->calculator->ctsDeposit($u, $regime, $start, $end, [
                'salary' => $salary, 'family_allowance' => $u->profile?->family_allowance ? $settings->familyAllowance() : 0.0,
                'other_computable' => 0.0, 'ot_cts' => $averages['cts'], 'ot_gratification' => $averages['gratification'],
            ]);

            return $row + ['amount' => $calc['amount'] ?? null, 'calc' => $calc, 'deposit_id' => null, 'note' => match (true) {
                $calc !== null => null,
                ! $regime      => 'Sin régimen laboral en su ficha',
                ! $regime->cts_per_year => 'Su régimen no tiene CTS',
                default        => 'No completó un mes en el semestre',
            }];
        });
    }

    /** @return array{0: int, 1: int} año y mes del depósito (5 o 11) */
    private function period(Request $request): array
    {
        [$year, $month] = CtsDeposit::currentSemester(CarbonImmutable::now(tenant_timezone()));

        return [$request->integer('year', $year) ?: $year, in_array($request->integer('month'), [5, 11], true) ? $request->integer('month') : $month];
    }

    private function tenant(): Tenant
    {
        $tenant = Tenant::find(current_tenant_id_or_null());
        abort_unless($tenant, 422, 'Elige una empresa.');

        return $tenant;
    }
}
