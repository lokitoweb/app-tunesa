<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * AttendanceWebController (Web)
 *
 * Gestión, auditoría visual y aprobación de asistencias,
 * geocercas registradas, fotos selfie y justificaciones.
 */
class AttendanceWebController extends Controller
{
    /**
     * Listado general de asistencias con filtros forenses.
     */
    public function index(Request $request): View
    {
        $tenantId = Auth::user()->tenant_id;

        // Rango de fechas por defecto: hoy
        $startDate = $request->input('start_date', Carbon::today()->toDateString());
        $endDate   = $request->input('end_date', Carbon::today()->toDateString());

        $query = Attendance::where('tenant_id', $tenantId)
            ->whereBetween('work_date', [$startDate, $endDate])
            ->with(['user', 'branch', 'shift'])
            ->orderBy('work_date', 'desc')
            ->orderBy('clock_in_at', 'desc');

        // Filtro por sucursal
        if ($branchId = $request->input('branch_id')) {
            $query->where('branch_id', $branchId);
        }

        // Filtro por estado
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Filtro por cumplimiento de geocerca
        if ($request->filled('within_geofence')) {
            $query->where('clock_in_within_geofence', $request->boolean('within_geofence'));
        }

        // Filtro por búsqueda de empleado
        if ($search = $request->input('search')) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('employee_code', 'like', "%{$search}%");
            });
        }

        $attendances = $query->paginate(20)->withQueryString();
        $branches = Branch::where('tenant_id', $tenantId)->get();

        // Conteo rápido para cabecera de filtros
        $summary = [
            'total'      => Attendance::where('tenant_id', $tenantId)->whereBetween('work_date', [$startDate, $endDate])->count(),
            'on_time'    => Attendance::where('tenant_id', $tenantId)->whereBetween('work_date', [$startDate, $endDate])->where('status', 'present')->count(),
            'late'       => Attendance::where('tenant_id', $tenantId)->whereBetween('work_date', [$startDate, $endDate])->where('status', 'late')->count(),
            'out_of_geo' => Attendance::where('tenant_id', $tenantId)->whereBetween('work_date', [$startDate, $endDate])->where('clock_in_within_geofence', false)->count(),
        ];

        return view('attendance.index', compact(
            'attendances',
            'branches',
            'startDate',
            'endDate',
            'summary'
        ));
    }

    /**
     * Vista de detalle y auditoría individual de la marcación.
     */
    public function show(int $id): View
    {
        $tenantId = Auth::user()->tenant_id;

        $attendance = Attendance::where('tenant_id', $tenantId)
            ->with(['user', 'branch', 'shift', 'reviewer'])
            ->findOrFail($id);

        return view('attendance.show', compact('attendance'));
    }

    /**
     * Aprueba o rechaza la justificación enviada por el empleado.
     */
    public function reviewJustification(Request $request, int $id): RedirectResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $attendance = Attendance::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'justification_status' => ['required', 'in:approved,rejected'],
            'review_notes'         => ['nullable', 'string', 'max:500'],
        ]);

        $statusToApply = ($validated['justification_status'] === 'approved')
            ? 'excused'
            : $attendance->status;

        $attendance->update([
            'justification_status' => $validated['justification_status'],
            'status'               => $statusToApply,
            'reviewed_by'          => Auth::id(),
            'reviewed_at'          => Carbon::now(),
            'notes'                => ($attendance->notes ? $attendance->notes . "\n---\n" : '') .
                                      "Revisión: {$validated['justification_status']}. Nota: " . ($validated['review_notes'] ?? 'Sin comentarios adicionales.'),
        ]);

        $action = $validated['justification_status'] === 'approved' ? 'aprobada' : 'rechazada';

        return back()->with('success', "La justificación fue {$action} correctamente.");
    }

    /**
     * Archivo de sustento de la justificación (disco privado).
     */
    public function justificationFile(int $id)
    {
        $attendance = Attendance::findOrFail($id);

        abort_unless(
            $attendance->justification_file && Storage::disk('private')->exists($attendance->justification_file),
            404
        );

        return response()->file(Storage::disk('private')->path($attendance->justification_file), [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
