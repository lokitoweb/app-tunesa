<?php

namespace App\Modules\Attendance\Controllers\Web;

use App\Modules\Attendance\Exports\AttendanceExport;
use App\Modules\Core\Controllers\Controller;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
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
    public function index(Request $request): View|\Illuminate\Http\JsonResponse|\Illuminate\Http\Response
    {
        $tenantId = current_tenant_id_or_null();

        $preset = $request->input('preset');
        $today  = Carbon::today();

        if ($preset) {
            switch ($preset) {
                case 'today':
                    $startDate = $today->toDateString();
                    $endDate   = $today->toDateString();
                    break;
                case 'yesterday':
                    $startDate = $today->copy()->subDay()->toDateString();
                    $endDate   = $today->copy()->subDay()->toDateString();
                    break;
                case 'week':
                case 'last_7_days':
                    $startDate = $today->copy()->subDays(6)->toDateString();
                    $endDate   = $today->toDateString();
                    break;
                case 'last_30_days':
                    $startDate = $today->copy()->subDays(29)->toDateString();
                    $endDate   = $today->toDateString();
                    break;
                case 'month':
                case 'this_month':
                    $startDate = $today->copy()->startOfMonth()->toDateString();
                    $endDate   = $today->toDateString();
                    break;
                case 'prev_month':
                    $startDate = $today->copy()->subMonth()->startOfMonth()->toDateString();
                    $endDate   = $today->copy()->subMonth()->endOfMonth()->toDateString();
                    break;
                default:
                    $startDate = $request->input('start_date', $today->copy()->startOfMonth()->toDateString());
                    $endDate   = $request->input('end_date', $today->toDateString());
                    break;
            }
        } else {
            $startDate = $request->input('start_date', $today->copy()->startOfMonth()->toDateString());
            $endDate   = $request->input('end_date', $today->toDateString());
        }

        $presetLabel = match ($preset) {
            'today' => 'Hoy',
            'yesterday' => 'Ayer',
            'week', 'last_7_days' => 'Últimos 7 días',
            'last_30_days' => 'Últimos 30 días',
            'month', 'this_month' => 'Este mes',
            'prev_month' => 'Mes anterior',
            default => ($startDate === $today->copy()->startOfMonth()->toDateString() && $endDate === $today->toDateString()) ? 'Este mes' : 'Personalizado'
        };

        $branchId  = $request->input('branch_id');
        $status    = $request->input('status');
        $search    = $request->input('search');

        // Consulta base acotada por empresa, permisos, rango de fechas y sucursal
        $baseQuery = Attendance::forTenant($tenantId)
            ->visibleTo($request->user())
            ->whereBetween('work_date', [$startDate, $endDate])
            ->with(['user', 'branch', 'shift']);

        if ($branchId) {
            $baseQuery->where('branch_id', $branchId);
        }

        // Estadísticas y contadores para los badges del periodo seleccionado (sobre la consulta base)
        $allBaseRecords  = (clone $baseQuery)->get();
        $totalRecords    = $allBaseRecords->count();
        $onTimeCount     = $allBaseRecords->filter(fn($r) => $r->status === 'present' && ($r->late_minutes ?? 0) == 0)->count();
        $lateCount       = $allBaseRecords->filter(fn($r) => $r->status === 'late' || ($r->late_minutes ?? 0) > 0)->count();
        $absentCount     = $allBaseRecords->filter(fn($r) => $r->status === 'absent' && ($r->justification_status ?? 'none') !== 'approved')->count();
        $excusedCount    = $allBaseRecords->filter(fn($r) => in_array($r->status, ['excused', 'justified'], true) || ($r->justification_status ?? 'none') === 'approved')->count();
        $totalHours      = (float) $allBaseRecords->sum('worked_hours');
        $overtimeHours   = (float) $allBaseRecords->sum('overtime_hours');

        $punctualityRate = $totalRecords > 0 ? round(($onTimeCount / $totalRecords) * 100, 1) : 0;

        // Consulta filtrada para la tabla
        $query = clone $baseQuery;

        if ($status === 'present') {
            $query->where('status', 'present')
                  ->where(function ($q) {
                      $q->whereNull('late_minutes')->orWhere('late_minutes', '<=', 0);
                  });
        } elseif ($status === 'late') {
            $query->where(function ($q) {
                $q->where('status', 'late')
                  ->orWhere('late_minutes', '>', 0);
            });
        } elseif ($status === 'absent') {
            $query->where('status', 'absent')
                  ->where(function ($q) {
                      $q->whereNull('justification_status')->orWhere('justification_status', '!=', 'approved');
                  });
        } elseif ($status === 'excused') {
            $query->where(function ($q) {
                $q->whereIn('status', ['excused', 'justified'])
                  ->orWhere('justification_status', 'approved');
            });
        } elseif ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('employee_code', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->input('per_page', 25);
        if (!in_array($perPage, [10, 20, 25, 50, 100], true)) {
            $perPage = 25;
        }

        $attendances = $query->orderBy('work_date', 'desc')->orderBy('clock_in_at', 'desc')->paginate($perPage)->withQueryString();
        $branches = Branch::forTenant($tenantId)->visibleTo($request->user())->orderBy('name')->get();

        if ($request->ajax()) {
            if ($request->has('tab_content')) {
                return view('reports.partials.marcaciones-content', compact(
                    'attendances',
                    'branches',
                    'startDate',
                    'endDate',
                    'preset',
                    'presetLabel',
                    'branchId',
                    'status',
                    'search',
                    'totalRecords',
                    'onTimeCount',
                    'lateCount',
                    'absentCount',
                    'excusedCount',
                    'totalHours',
                    'overtimeHours',
                    'punctualityRate'
                ));
            }

            $tableHtml = view('reports.partials.table', compact('attendances'))->render();

            return response()->json([
                'html' => $tableHtml,
                'kpis' => [
                    'totalRecords'    => (string) $totalRecords,
                    'punctualityRate' => (string) $punctualityRate,
                    'onTimeCount'     => (string) $onTimeCount,
                    'lateCount'       => (string) $lateCount,
                    'absentCount'     => (string) $absentCount,
                    'totalHours'      => number_format($totalHours, 1),
                    'overtimeHours'   => number_format($overtimeHours, 1),
                ],
                'counts' => [
                    'all'     => $totalRecords,
                    'present' => $onTimeCount,
                    'late'    => $lateCount,
                    'absent'  => $absentCount,
                    'excused' => $excusedCount,
                ],
            ]);
        }

        return view('reports.index', compact(
            'attendances',
            'branches',
            'startDate',
            'endDate',
            'preset',
            'presetLabel',
            'branchId',
            'status',
            'search',
            'totalRecords',
            'onTimeCount',
            'lateCount',
            'absentCount',
            'excusedCount',
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

        $export = new AttendanceExport($tenantId, $startDate, $endDate, $branchId, $status, $request->user());
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
            ->visibleTo($request->user())
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
