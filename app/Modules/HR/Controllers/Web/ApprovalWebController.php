<?php

namespace App\Modules\HR\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\OvertimeApproval;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\PrivateFile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * ApprovalWebController (Web)
 *
 * Bandeja de aprobaciones con flujo por estados (pendiente → aprobado /
 * rechazado) para vacaciones, permisos, licencias médicas y justificaciones
 * de asistencia, y horas extra. El supervisor solo ve a su equipo; RRHH y el
 * administrador ven toda la empresa.
 */
class ApprovalWebController extends Controller
{
    public function __construct(private readonly OvertimeApproval $overtime) {}

    public function index(Request $request): View
    {
        $user   = Auth::user();
        $tab    = $request->input('tab', 'leave');
        $status = $request->input('status', 'pending');

        abort_unless(in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true), 404);
        if ($tab === 'justifications' && ! $user->can('attendance.review')) {
            $tab = 'leave';
        }
        if ($tab === 'overtime' && $status === 'cancelled') {
            $status = 'pending';
        }

        $counts = [
            'leave'          => $this->leaveQuery($user)->where('status', 'pending')->count(),
            'justifications' => $user->can('attendance.review')
                ? Attendance::where('justification_status', 'pending')->count()
                : 0,
            'overtime'       => $this->overtime->pendingCount($user),
        ];

        $perPage = in_array((int) $request->input('per_page'), [10, 20, 50, 100], true) ? (int) $request->input('per_page') : 20;

        if ($tab === 'overtime') {
            $items = $this->overtime->query($user)
                ->where('overtime_status', $status)
                ->with(['user:id,first_name,last_name,employee_code,avatar_path', 'branch:id,name', 'shift:id,name,daily_hours', 'overtimeReviewer:id,first_name,last_name'])
                ->orderBy($status === 'pending' ? 'work_date' : 'overtime_reviewed_at', $status === 'pending' ? 'asc' : 'desc')
                ->orderBy('id')
                ->paginate($perPage)
                ->withQueryString();
        } elseif ($tab === 'justifications') {
            $items = Attendance::where('justification_status', $status === 'cancelled' ? 'none' : $status)
                ->with([
                    'user:id,first_name,last_name,employee_code,avatar_path',
                    'branch:id,name',
                    'shift:id,name,start_time,end_time,grace_period_minutes',
                    'reviewer:id,first_name,last_name',
                ])
                ->orderByDesc('work_date')
                ->paginate($perPage)
                ->withQueryString();
        } else {
            $items = $this->leaveQuery($user)
                ->where('status', $status)
                ->when($request->type, fn ($q) => $q->where('type', $request->type))
                ->with([
                    'employee:id,first_name,last_name,employee_code,avatar_path,department_id', 'reviewer:id,first_name,last_name',
                    // "Revisa": jefe de área o supervisor de sede, y RR. HH.
                    'employee.department.head:id,first_name,last_name', 'employee.branches.supervisor:id,first_name,last_name',
                ])
                ->orderBy('start_date')
                ->paginate($perPage)
                ->withQueryString();
        }

        return view('approvals.index', compact('items', 'tab', 'status', 'counts'));
    }

    /** Aprobar o rechazar una solicitud de vacaciones / permiso / licencia */
    public function reviewLeave(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'notes'    => ['required_if:decision,rejected', 'nullable', 'string', 'max:1000'],
        ], [
            'notes.required_if' => 'Indica el motivo del rechazo.',
        ]);

        $user  = Auth::user();
        $leave = $this->leaveQuery($user)->findOrFail($id);

        if ($leave->user_id === $user->id) {
            return back()->with('error', 'No puedes aprobar tus propias solicitudes.');
        }

        if (! $leave->isPending()) {
            return back()->with('error', 'La solicitud ya fue revisada.');
        }

        $leave->update([
            'status'       => $validated['decision'],
            'reviewed_by'  => $user->id,
            'reviewed_at'  => now(),
            'review_notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('success', 'Solicitud ' . ($validated['decision'] === 'approved' ? 'aprobada' : 'rechazada') . '.');
    }

    /** Aprobar (todas o menos horas) o rechazar las horas extra de una jornada */
    public function reviewOvertime(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'hours'    => ['nullable', 'numeric', 'min:0', 'max:24'],
            'notes'    => ['nullable', 'string', 'max:500'],
        ]);

        $attendance = $this->overtime->query(Auth::user())->findOrFail($id);
        $this->overtime->review($attendance, Auth::user(), $validated['decision'],
            isset($validated['hours']) ? (float) $validated['hours'] : null, $validated['notes'] ?? null);

        return back()->with('success', 'Horas extra ' . ($validated['decision'] === 'approved' ? 'aprobadas' : 'rechazadas') . '.');
    }

    /** Aprobar varias jornadas a la vez, con todas sus horas calculadas */
    public function approveOvertimeBulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids'   => ['required', 'array', 'max:100'],
            'ids.*' => ['integer'],
        ], ['ids.required' => 'Marca al menos una jornada.']);

        $user = Auth::user();
        $approved = 0;

        $this->overtime->query($user)
            ->whereIn('id', $validated['ids'])
            ->where('overtime_status', Attendance::OVERTIME_PENDING)
            ->where('user_id', '!=', $user->id)
            ->get()
            ->each(function (Attendance $attendance) use ($user, &$approved) {
                $this->overtime->review($attendance, $user, Attendance::OVERTIME_APPROVED);
                $approved++;
            });

        return back()->with('success', $approved === 1 ? 'Se aprobó 1 jornada con horas extra.' : "Se aprobaron {$approved} jornadas con horas extra.");
    }

    /** Sustento adjunto de una solicitud (certificado médico, etc.) */
    public function leaveAttachment(int $id)
    {
        $leave = $this->leaveQuery(Auth::user())->findOrFail($id);

        return PrivateFile::response('private', $leave->attachment_path, "sustento-solicitud-{$leave->id}." . pathinfo((string) $leave->attachment_path, PATHINFO_EXTENSION));
    }

    private function leaveQuery(User $user): Builder
    {
        return LeaveRequest::approvableBy($user);
    }
}
