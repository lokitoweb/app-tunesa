<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LeaveRequestController
 * Solicitudes de vacaciones, permisos y licencias médicas.
 *
 * Empleado:    /api/leave-requests            (mis solicitudes)
 * Aprobador:   /api/approvals/leave-requests  (supervisor: su equipo; RRHH/Admin: toda la empresa)
 */
class LeaveRequestController extends Controller
{
    // =========================================================================
    // EMPLEADO
    // =========================================================================

    /** Mis solicitudes */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'in:pending,approved,rejected,cancelled'],
            'type'   => ['sometimes', 'in:' . implode(',', LeaveRequest::TYPES)],
        ]);

        $requests = LeaveRequest::where('user_id', $request->user()->id)
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->type,   fn ($q) => $q->where('type',   $request->type))
            ->with('reviewer:id,first_name,last_name')
            ->orderByDesc('start_date')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $requests->getCollection()->map(fn ($r) => $this->format($r)),
            'meta'    => ['total' => $requests->total(), 'current_page' => $requests->currentPage()],
        ]);
    }

    /** Crear solicitud (multipart si adjunta sustento) */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'       => ['required', 'in:' . implode(',', LeaveRequest::TYPES)],
            'start_date' => ['required', 'date'],
            'end_date'   => ['required', 'date', 'after_or_equal:start_date'],
            'start_time' => ['sometimes', 'nullable', 'date_format:H:i', 'required_with:end_time'],
            'end_time'   => ['sometimes', 'nullable', 'date_format:H:i', 'after:start_time'],
            'reason'     => ['required', 'string', 'max:1000'],
            'attachment' => [
                $request->input('type') === 'medical_leave' ? 'required' : 'sometimes',
                'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120',
            ],
        ]);

        $user = $request->user();

        $overlaps = LeaveRequest::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'approved'])
            ->overlapping($validated['start_date'], $validated['end_date'])
            ->exists();

        if ($overlaps) {
            return response()->json([
                'success' => false,
                'message' => 'Ya tienes una solicitud pendiente o aprobada en esas fechas.',
                'code'    => 'OVERLAPPING_REQUEST',
            ], 409);
        }

        if ($request->hasFile('attachment')) {
            $validated['attachment_path'] = $request->file('attachment')
                ->store("leave-requests/{$user->tenant_id}/{$user->id}", 'private');
        }
        unset($validated['attachment']);

        $leave = LeaveRequest::create(array_merge($validated, [
            'user_id' => $user->id,
            'days'    => Carbon::parse($validated['start_date'])->diffInDays(Carbon::parse($validated['end_date'])) + 1,
            'status'  => 'pending',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Solicitud enviada.',
            'data'    => $this->format($leave),
        ], 201);
    }

    /** Ver una solicitud propia */
    public function show(Request $request, int $id): JsonResponse
    {
        $leave = LeaveRequest::where('user_id', $request->user()->id)
            ->with('reviewer:id,first_name,last_name')
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->format($leave)]);
    }

    /** Cancelar una solicitud propia pendiente */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $leave = LeaveRequest::where('user_id', $request->user()->id)->findOrFail($id);

        if (! $leave->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Solo puedes cancelar solicitudes pendientes.',
                'code'    => 'NOT_PENDING',
            ], 409);
        }

        $leave->update(['status' => 'cancelled']);

        return response()->json(['success' => true, 'message' => 'Solicitud cancelada.', 'data' => $this->format($leave)]);
    }

    // =========================================================================
    // APROBADORES
    // =========================================================================

    /** Bandeja de solicitudes para aprobar */
    public function pendingApprovals(Request $request): JsonResponse
    {
        $request->validate([
            'status'  => ['sometimes', 'in:pending,approved,rejected,cancelled'],
            'type'    => ['sometimes', 'in:' . implode(',', LeaveRequest::TYPES)],
            'user_id' => ['sometimes', 'integer'],
        ]);

        $requests = $this->approvableQuery($request->user())
            ->where('status', $request->input('status', 'pending'))
            ->when($request->type,    fn ($q) => $q->where('type', $request->type))
            ->when($request->user_id, fn ($q) => $q->where('user_id', $request->user_id))
            ->with(['employee:id,first_name,last_name,employee_code', 'reviewer:id,first_name,last_name'])
            ->orderBy('start_date')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $requests->getCollection()->map(fn ($r) => $this->format($r, withEmployee: true)),
            'meta'    => ['total' => $requests->total(), 'current_page' => $requests->currentPage()],
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        return $this->review($request, $id, 'approved');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        return $this->review($request, $id, 'rejected');
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function review(Request $request, int $id, string $status): JsonResponse
    {
        $request->validate([
            'notes' => [$status === 'rejected' ? 'required' : 'sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $reviewer = $request->user();
        $leave    = $this->approvableQuery($reviewer)->findOrFail($id);

        if ($leave->user_id === $reviewer->id) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes aprobar tus propias solicitudes.',
                'code'    => 'SELF_APPROVAL',
            ], 403);
        }

        if (! $leave->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'La solicitud ya fue revisada.',
                'code'    => 'NOT_PENDING',
            ], 409);
        }

        $leave->update([
            'status'       => $status,
            'reviewed_by'  => $reviewer->id,
            'reviewed_at'  => now(),
            'review_notes' => $request->input('notes'),
        ]);

        return response()->json([
            'success' => true,
            'message' => $status === 'approved' ? 'Solicitud aprobada.' : 'Solicitud rechazada.',
            'data'    => $this->format($leave->fresh(['employee', 'reviewer']), withEmployee: true),
        ]);
    }

    /**
     * Solicitudes que el usuario puede revisar: toda la empresa con
     * leave.approve_all; solo su equipo con leave.approve_team.
     */
    private function approvableQuery(User $user): Builder
    {
        $query = LeaveRequest::query();

        if ($user->hasPermission('leave.approve_all')) {
            return $query;
        }

        return $query->whereHas('employee.branches', fn ($q) => $q->where('branches.supervisor_id', $user->id));
    }

    private function format(LeaveRequest $leave, bool $withEmployee = false): array
    {
        $data = [
            'id'             => $leave->id,
            'type'           => $leave->type,
            'type_label'     => $leave->type_label,
            'start_date'     => $leave->start_date?->toDateString(),
            'end_date'       => $leave->end_date?->toDateString(),
            'start_time'     => $leave->start_time ? substr($leave->start_time, 0, 5) : null,
            'end_time'       => $leave->end_time ? substr($leave->end_time, 0, 5) : null,
            'days'           => (float) $leave->days,
            'reason'         => $leave->reason,
            'has_attachment' => (bool) $leave->attachment_path,
            'status'         => $leave->status,
            'review_notes'   => $leave->review_notes,
            'reviewed_at'    => $leave->reviewed_at?->toIso8601String(),
            'reviewer'       => $leave->reviewer?->full_name,
            'created_at'     => $leave->created_at?->toIso8601String(),
        ];

        if ($withEmployee) {
            $data['employee'] = $leave->employee ? [
                'id'            => $leave->employee->id,
                'name'          => $leave->employee->full_name,
                'employee_code' => $leave->employee->employee_code,
            ] : null;
        }

        return $data;
    }
}
