<?php

namespace App\Modules\HR\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\HR\Models\EmployeeSettlement;
use App\Modules\HR\Models\LaborRegime;
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

/** Cese del colaborador y su liquidación de beneficios sociales, con los parámetros de su régimen */
class SettlementWebController extends Controller
{
    public function __construct(private readonly SettlementCalculator $calculator, private readonly AuditLogger $audit) {}

    public function create(Request $request, int $id, VacationBalance $vacations): Response
    {
        $employee = $this->employee($id);
        $regime = $this->regime($employee);
        $end = $this->date($request->query('termination_date')) ?? CarbonImmutable::parse(($employee->termination_date ?? today())->toDateString());
        $reason = array_key_exists((string) $request->query('reason'), EmployeeSettlement::REASONS) ? $request->query('reason') : 'resignation';
        $input = [
            'termination_date' => $end->toDateString(),
            'reason'           => $reason,
            'salary'           => $request->has('salary') ? max(0, (float) $request->query('salary')) : (float) $employee->base_salary,
            'pending_days'     => $request->has('pending_days') ? max(0, (int) $request->query('pending_days'))
                : max(0, (int) ($employee->hire_date ? ($vacations->for($employee, $end)['balance'] ?? 0) : 0)),
            'indemnity'        => $request->has('indemnity') ? $request->boolean('indemnity') : $reason === 'arbitrary_dismissal',
        ];

        return Inertia::render('HR/Pages/Settlements/Create', [
            'employee' => [
                'id' => $employee->id, 'name' => $employee->full_name, 'document' => $employee->document_number, 'position' => $employee->position,
                'hire_date' => $employee->hire_date?->toDateString(), 'status' => $employee->status, 'base_salary' => (float) $employee->base_salary,
            ],
            'regime'   => $regime?->only(['code', 'name', ...LaborRegime::PARAMS]),
            'input'    => $input,
            'result'   => $employee->hire_date && $end->gte($employee->hire_date) ? $this->calculate($employee, $regime, $input) : null,
            'reasons'  => EmployeeSettlement::REASONS,
            'history'  => EmployeeSettlement::where('user_id', $employee->id)->latest('id')->get(['id', 'termination_date', 'reason', 'total', 'created_at'])
                ->map(fn ($s) => ['id' => $s->id, 'date' => $s->termination_date->format('d/m/Y'), 'reason' => EmployeeSettlement::REASONS[$s->reason] ?? $s->reason, 'total' => $s->total]),
        ]);
    }

    public function store(Request $request, int $id): RedirectResponse
    {
        $employee = $this->employee($id);
        abort_unless($employee->hire_date, 422, 'Registra la fecha de ingreso antes de calcular la liquidación.');
        $input = $request->validate([
            'termination_date' => ['required', 'date', 'after_or_equal:' . $employee->hire_date->toDateString()],
            'reason'           => ['required', Rule::in(array_keys(EmployeeSettlement::REASONS))],
            'salary'           => ['required', 'numeric', 'min:0', 'max:9999999'],
            'pending_days'     => ['required', 'integer', 'min:0', 'max:999'],
            'indemnity'        => ['boolean'],
            'notes'            => ['nullable', 'string', 'max:1000'],
        ], [], ['termination_date' => 'la fecha de cese', 'reason' => 'el motivo', 'salary' => 'la remuneración', 'pending_days' => 'los días pendientes']);
        $regime = $this->regime($employee);
        $result = $this->calculate($employee, $regime, $input);
        $end = CarbonImmutable::parse($input['termination_date']);

        $settlement = DB::transaction(function () use ($employee, $regime, $input, $result, $end, $request) {
            $settlement = EmployeeSettlement::create([
                'tenant_id' => $employee->tenant_id, 'user_id' => $employee->id, 'labor_regime' => $regime?->code,
                'termination_date' => $end, 'reason' => $input['reason'], 'notes' => $input['notes'] ?? null,
                'data' => ['input' => $input, 'regime' => $regime?->only(['code', 'name', ...LaborRegime::PARAMS])] + $result,
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

    public function print(int $id): View
    {
        $settlement = EmployeeSettlement::with(['user', 'creator:id,first_name,last_name', 'tenant'])->findOrFail($id);
        abort_unless(request()->user()->canManage($settlement->user), 403);

        return view('settlements.print', ['s' => $settlement, 'reasons' => EmployeeSettlement::REASONS]);
    }

    private function calculate(User $employee, ?LaborRegime $regime, array $input): array
    {
        return $this->calculator->calculate($employee, $regime, CarbonImmutable::parse($input['termination_date']),
            (float) $input['salary'], (int) $input['pending_days'], (bool) ($input['indemnity'] ?? false));
    }

    private function employee(int $id): User
    {
        $employee = User::forTenant(current_tenant_id_or_null())->with('profile')->findOrFail($id);
        abort_unless(request()->user()->canManage($employee), 403);

        return $employee;
    }

    private function regime(User $employee): ?LaborRegime
    {
        $code = $employee->profile?->labor_regime;

        return $code ? LaborRegime::catalog((int) $employee->tenant_id)->firstWhere('code', $code) : null;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        try {
            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
