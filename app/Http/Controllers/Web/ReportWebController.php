<?php

namespace App\Http\Controllers\Web;

use App\Exports\AttendanceExport;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ReportWebController (Web)
 *
 * Módulo de reportería analítica, métricas de cumplimiento,
 * y exportación masiva a Excel/CSV y PDF.
 */
class ReportWebController extends Controller
{
    /**
     * Vista interactiva de reportes con filtros y resumen.
     */
    public function index(Request $request): View
    {
        $tenantId = current_tenant_id_or_null();

        $startDate = $request->input('start_date', Carbon::today()->startOfMonth()->toDateString());
        $endDate   = $request->input('end_date', Carbon::today()->toDateString());
        $branchId  = $request->input('branch_id');
        $status    = $request->input('status');

        $query = Attendance::forTenant($tenantId)
            ->whereBetween('work_date', [$startDate, $endDate])
            ->with(['user', 'branch', 'shift']);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $attendances = (clone $query)->orderBy('work_date', 'desc')->paginate(25)->withQueryString();

        // Estadísticas agregadas
        $allRecords = (clone $query)->get();
        $totalRecords = $allRecords->count();
        $onTimeCount  = $allRecords->where('status', 'present')->count();
        $lateCount    = $allRecords->filter(fn($r) => $r->status === 'late' || ($r->late_minutes ?? 0) > 0)->count();
        $absentCount  = $allRecords->where('status', 'absent')->count();
        $totalHours   = $allRecords->sum('worked_hours');
        $overtimeHours = $allRecords->sum('overtime_hours');

        $punctualityRate = $totalRecords > 0 ? round(($onTimeCount / $totalRecords) * 100, 1) : 0;

        $branches = Branch::forTenant($tenantId)->get();

        return view('reports.index', compact(
            'attendances',
            'branches',
            'startDate',
            'endDate',
            'branchId',
            'status',
            'totalRecords',
            'onTimeCount',
            'lateCount',
            'absentCount',
            'totalHours',
            'overtimeHours',
            'punctualityRate'
        ));
    }

    /**
     * Descarga de reporte estructurado en Excel / CSV.
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        $tenantId  = current_tenant_id_or_null();
        $startDate = $request->input('start_date', Carbon::today()->startOfMonth()->toDateString());
        $endDate   = $request->input('end_date', Carbon::today()->toDateString());
        $branchId  = $request->input('branch_id') ? (int) $request->input('branch_id') : null;
        $status    = $request->input('status');

        $export = new AttendanceExport($tenantId, $startDate, $endDate, $branchId, $status);
        $fileName = sprintf('asistencias_%s_al_%s.csv', $startDate, $endDate);

        return $export->download($fileName);
    }

    /**
     * Vista de impresión ejecutiva formateada para PDF.
     */
    public function exportPdf(Request $request): View
    {
        $tenantId  = current_tenant_id_or_null();
        $tenant    = $tenantId ? Tenant::findOrFail($tenantId) : new Tenant(['company_name' => 'Todas las empresas']);
        $startDate = $request->input('start_date', Carbon::today()->startOfMonth()->toDateString());
        $endDate   = $request->input('end_date', Carbon::today()->toDateString());
        $branchId  = $request->input('branch_id');

        $query = Attendance::forTenant($tenantId)
            ->whereBetween('work_date', [$startDate, $endDate])
            ->with(['user', 'branch'])
            ->orderBy('work_date', 'asc');

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $attendances = $query->get();

        return view('reports.pdf', compact('attendances', 'tenant', 'startDate', 'endDate'));
    }
}
