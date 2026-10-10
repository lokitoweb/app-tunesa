<?php

namespace App\Modules\HR\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * ApprovalWebController (Web)
 *
 * Bandeja de aprobaciones con flujo por estados (pendiente → aprobado /
 * rechazado) para vacaciones, permisos, licencias médicas y justificaciones
 * de asistencia. El supervisor solo ve a su equipo; RRHH y el administrador
 * ven toda la empresa.
 */
class ApprovalWebController extends Controller
{
    public function index(Request $request): View
    {
        $user   = Auth::user();
        $tab    = $request->input('tab', 'leave');
        $status = $request->input('status', 'pending');

        abort_unless(in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true), 404);
        if ($tab === 'justifications' && ! $user->can('attendance.review')) {
            $tab = 'leave';
        }

        $counts = [
            'leave'          => $this->leaveQuery($user)->where('status', 'pending')->count(),
            'justifications' => $user->can('attendance.review')
                ? Attendance::where('justification_status', 'pending')->count()
                : 0,
        ];

        if ($tab === 'justifications') {
            $items = Attendance::where('justification_status', $status === 'cancelled' ? 'none' : $status)
                ->with(['user:id,first_name,last_name,employee_code', 'branch:id,name', 'reviewer:id,first_name,last_name'])
                ->orderByDesc('work_date')
                ->paginate(20)
                ->withQueryString();
        } else {
            $items = $this->leaveQuery($user)
                ->where('status', $status)
                ->when($request->type, fn ($q) => $q->where('type', $request->type))
                ->with(['employee:id,first_name,last_name,employee_code', 'reviewer:id,first_name,last_name'])
                ->orderBy('start_date')
                ->paginate(20)
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

    /** Sustento adjunto de una solicitud (certificado médico, etc.) */
    public function leaveAttachment(int $id)
    {
        $leave = $this->leaveQuery(Auth::user())->findOrFail($id);

        abort_unless($leave->attachment_path && Storage::disk('private')->exists($leave->attachment_path), 404);

        return response()->file(Storage::disk('private')->path($leave->attachment_path), [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function leaveQuery(User $user): Builder
    {
        $query = LeaveRequest::query();

        if ($user->can('leave.approve_all')) {
            return $query;
        }

        return $query->whereHas('employee.branches', fn ($q) => $q->where('branches.supervisor_id', $user->id));
    }
}
