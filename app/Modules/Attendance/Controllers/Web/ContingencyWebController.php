<?php

namespace App\Modules\Attendance\Controllers\Web;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendanceContingency;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Department;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asistencia › Plan de contingencia: quien tiene attendance.contingency lo activa para toda la
 * empresa, sedes, áreas o personas, con motivo y hora de fin (se apaga solo), lo desactiva antes
 * si hace falta y ve el cierre: quién marcó, quién no y las justificaciones pendientes.
 */
class ContingencyWebController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $plans = AttendanceContingency::with(['creator:id,first_name,last_name', 'ender:id,first_name,last_name'])->latest('id')->limit(30)->get();
        $selected = $plans->firstWhere('id', $request->integer('plan')) ?? $plans->first();
        $tz = tenant_timezone();

        return Inertia::render('Attendance/Pages/Contingency/Index', [
            'plans' => $plans->map(fn (AttendanceContingency $c) => [
                'id' => $c->id, 'scope' => AttendanceContingency::SCOPES[$c->scope] ?? $c->scope, 'targets' => $this->targetNames($c), 'reason' => $c->reason,
                'starts_at' => $c->starts_at->setTimezone($tz)->format('d/m/Y H:i'), 'ends_at' => $c->ends_at->setTimezone($tz)->format('d/m/Y H:i'),
                'ended_at' => $c->ended_at?->setTimezone($tz)->format('d/m/Y H:i'), 'active' => $c->isActive(),
                'by' => $c->creator?->full_name, 'ended_by' => $c->ender?->full_name,
            ])->values(),
            'report' => $selected ? $this->report($selected) : null,
            'options' => [
                'scopes' => AttendanceContingency::SCOPES,
                'branches' => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']),
                'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
                'people' => $this->clocking()->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'document_number'])
                    ->map(fn ($u) => ['id' => $u->id, 'name' => "{$u->full_name} · {$u->document_number}"]),
                'max_hours' => AttendanceContingency::MAX_HOURS,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenantId = (int) current_tenant_id();
        $data = $request->validate([
            'scope'       => ['required', Rule::in(array_keys(AttendanceContingency::SCOPES))],
            'scope_ids'   => ['exclude_if:scope,all', 'required', 'array', 'min:1'],
            'scope_ids.*' => ['integer'],
            'reason'      => ['required', 'string', 'max:200'],
            'hours'       => ['required', 'integer', 'min:1', 'max:' . AttendanceContingency::MAX_HOURS],
        ], [], ['scope' => 'el alcance', 'scope_ids' => 'a quiénes aplica', 'reason' => 'el motivo', 'hours' => 'la duración']);

        // Solo sedes, áreas o personas de la empresa
        $table = ['branch' => 'branches', 'department' => 'departments', 'users' => 'users'][$data['scope']] ?? null;
        $ids = $table ? array_values(array_unique(array_map('intval', $data['scope_ids']))) : null;
        if ($table && \Illuminate\Support\Facades\DB::table($table)->where('tenant_id', $tenantId)->whereIn('id', $ids)->count() !== count($ids)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['scope_ids' => 'Alguna sede, área o persona no es de tu empresa.']);
        }

        $now = CarbonImmutable::now('UTC');
        $plan = AttendanceContingency::create([
            'tenant_id' => $tenantId, 'scope' => $data['scope'], 'scope_ids' => $ids, 'reason' => trim($data['reason']),
            'starts_at' => $now, 'ends_at' => $now->addHours((int) $data['hours']), 'created_by' => $request->user()->id,
        ]);
        $this->audit->log('attendance.contingency_started', $plan, ['scope' => $plan->scope, 'targets' => $this->targetNames($plan), 'reason' => $plan->reason, 'hours' => (int) $data['hours']]);

        return redirect()->route('attendance.contingency.index', ['plan' => $plan->id])
            ->with('success', 'Plan de contingencia activo hasta las ' . $plan->ends_at->setTimezone(tenant_timezone())->format('H:i \d\e\l d/m') . '. Las personas cubiertas marcan desde Mi espacio.');
    }

    public function end(Request $request, int $id): RedirectResponse
    {
        $plan = AttendanceContingency::findOrFail($id);
        abort_unless($plan->isActive(), 422, 'Este plan ya no está activo.');
        $plan->update(['ended_at' => CarbonImmutable::now('UTC'), 'ended_by' => $request->user()->id]);
        $this->audit->log('attendance.contingency_ended', $plan, ['reason' => $plan->reason]);

        return back()->with('success', 'Plan de contingencia desactivado. Desde ahora se marca solo con la app.');
    }

    /** Cierre del plan: personas cubiertas, sus marcas de contingencia y justificaciones pendientes */
    private function report(AttendanceContingency $plan): array
    {
        $punches = AttendancePunch::where('contingency_id', $plan->id)->get(['user_id', 'type', 'server_time'])->groupBy('user_id');
        $people = $this->covered($plan)->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'document_number']);
        $from = $plan->starts_at->setTimezone(tenant_timezone())->toDateString();
        $to = ($plan->ended_at ?? $plan->ends_at)->setTimezone(tenant_timezone())->toDateString();
        $pending = Attendance::whereIn('user_id', $people->pluck('id'))->whereBetween('work_date', [$from, $to])
            ->where('justification_status', 'pending')->count();

        return [
            'id' => $plan->id,
            'covered' => $people->count(),
            'marked' => $people->filter(fn ($u) => $punches->has($u->id))->count(),
            'pending_justifications' => $pending,
            'people' => $people->map(fn ($u) => [
                'name' => $u->full_name, 'document' => $u->document_number,
                'punches' => $punches->get($u->id, collect())->sortBy('server_time')
                    ->map(fn ($p) => ['type' => $p->type, 'time' => $p->server_time->setTimezone(tenant_timezone())->format('d/m H:i')])->values(),
            ])->values(),
        ];
    }

    /** Personal que marca asistencia */
    private function clocking(): Builder
    {
        return User::query()->where('status', 'active')->whereIn('role', User::CLOCKING_ROLES);
    }

    private function covered(AttendanceContingency $plan): Builder
    {
        $ids = $plan->scope_ids ?? [];

        return match ($plan->scope) {
            'branch'     => $this->clocking()->whereHas('branches', fn ($q) => $q->whereIn('branches.id', $ids)),
            'department' => $this->clocking()->whereIn('department_id', $ids),
            'users'      => $this->clocking()->whereIn('id', $ids),
            default      => $this->clocking(),
        };
    }

    private function targetNames(AttendanceContingency $plan): ?string
    {
        $ids = $plan->scope_ids ?? [];

        return match ($plan->scope) {
            'branch'     => Branch::whereIn('id', $ids)->pluck('name')->implode(', '),
            'department' => Department::whereIn('id', $ids)->pluck('name')->implode(', '),
            'users'      => count($ids) . ' persona(s)',
            default      => null,
        };
    }
}
