<?php

namespace App\Modules\Attendance\Controllers\Web;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\OffShiftAuthorization;
use App\Modules\Attendance\Notifications\OffShiftAuthorized;
use App\Modules\Attendance\Services\ScheduleGate;
use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Marcación fuera de turno: RRHH o Gerencia habilita a una persona a marcar
 * un día en que no tenía turno programado (descanso o sin programación).
 * Se avisa a la persona y queda en la bitácora.
 */
class OffShiftAuthorizationWebController extends Controller
{
    public function __construct(
        private readonly ScheduleGate $gate,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $today = Carbon::now(tenant_timezone())->toDateString();

        $authorizations = OffShiftAuthorization::with(['user:id,first_name,last_name,employee_code,position', 'authorizer:id,first_name,last_name'])
            ->when($request->query('period') !== 'past', fn ($q) => $q->whereDate('work_date', '>=', $today), fn ($q) => $q->whereDate('work_date', '<', $today))
            ->orderBy('work_date', $request->query('period') === 'past' ? 'desc' : 'asc')
            ->paginate(25)->withQueryString();

        // Si ya marcó ese día, la autorización se usó
        $used = Attendance::whereIn('user_id', $authorizations->pluck('user_id'))
            ->whereIn('work_date', $authorizations->map(fn ($a) => $a->work_date->toDateString()))
            ->whereNotNull('clock_in_at')
            ->get(['user_id', 'work_date'])
            ->map(fn ($a) => $a->user_id . '|' . $a->work_date->toDateString())
            ->flip();

        return view('attendance.offshift', [
            'authorizations' => $authorizations,
            'used'           => $used,
            'employees'      => $this->employees($request),
            'today'          => $today,
            'selectedUser'   => $request->integer('user_id') ?: null,
            'period'         => $request->query('period') === 'past' ? 'past' : 'upcoming',
            'hasTenant'      => (bool) current_tenant_id_or_null(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(current_tenant_id_or_null(), 422, 'Elige una empresa en el selector superior.');
        $today = Carbon::now(tenant_timezone())->toDateString();

        $data = $request->validate([
            'user_id'   => ['required', 'integer', Rule::exists('users', 'id')->where('tenant_id', current_tenant_id_or_null())->where('status', 'active')],
            'work_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:' . $today, 'before_or_equal:' . Carbon::parse($today)->addDays(60)->toDateString()],
            'reason'    => ['required', 'string', 'min:5', 'max:300'],
        ], [
            'user_id.required'           => 'Elige al colaborador.',
            'user_id.exists'             => 'Elige un colaborador activo de la empresa.',
            'work_date.after_or_equal'   => 'La fecha debe ser hoy o posterior. Para días pasados usa la corrección manual de la jornada.',
            'work_date.before_or_equal'  => 'Solo se puede autorizar hasta 60 días adelante.',
            'reason.required'            => 'Escribe el motivo (queda en la bitácora).',
            'reason.min'                 => 'Escribe un motivo un poco más claro.',
        ]);

        $employee = User::findOrFail($data['user_id']);
        abort_unless($request->user()->canManage($employee) || $request->user()->hasPermission('attendance.view_all'), 403);

        $status = $this->gate->statusFor($employee, $data['work_date']);
        if ($status['scheduled']) {
            return back()->withInput()->with('info', "{$employee->full_name} ya tiene turno programado ese día: puede marcar sin autorización.");
        }

        $authorization = OffShiftAuthorization::updateOrCreate(
            ['user_id' => $employee->id, 'work_date' => $data['work_date']],
            ['tenant_id' => $employee->tenant_id, 'reason' => $data['reason'], 'authorized_by' => $request->user()->id],
        );

        $this->audit->log('attendance.offshift_authorized', $employee, ['date' => $data['work_date'], 'reason' => $data['reason']]);
        rescue(fn () => $employee->notify(new OffShiftAuthorized($authorization)), report: true);

        return redirect()->route('attendance.offshift.index')->with('success',
            "Listo: {$employee->full_name} puede marcar el " . Carbon::parse($data['work_date'])->format('d/m/Y') . ' aunque no tenga turno.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $authorization = OffShiftAuthorization::with('user')->findOrFail($id);
        $authorization->delete();
        $this->audit->log('attendance.offshift_revoked', $authorization->user, ['date' => $authorization->work_date->toDateString()]);

        return back()->with('success', 'Autorización retirada.');
    }

    /** Colaboradores activos que este usuario puede ver (supervisor: su equipo) */
    private function employees(Request $request)
    {
        if (! current_tenant_id_or_null()) {
            return collect();
        }

        return User::where('status', 'active')
            ->where('role', '!=', 'super_admin')
            ->when(! $request->user()->hasPermission('attendance.view_all'), fn ($q) => $q->visibleTo($request->user()))
            ->orderBy('last_name')->orderBy('first_name')
            ->limit(3000)
            ->get(['id', 'first_name', 'last_name', 'employee_code']);
    }
}
