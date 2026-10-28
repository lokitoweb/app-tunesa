<?php

namespace App\Modules\Core\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Middleware\SetWebTenant;
use App\Modules\Core\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * DashboardController (Web)
 *
 * Administra el cuadro de mando en tiempo real con estadísticas operativas,
 * mapa interactivo Leaflet de geocercas y feed en vivo de marcaciones.
 *
 * La empresa sale del TenantContext (SetWebTenant), nunca de un dato nulo:
 * los usuarios de empresa ven solo la suya; el Super Admin ve la empresa que
 * eligió en el selector o, sin elegir, la vista global con cifras, sucursales
 * y marcaciones de todas las empresas.
 */
class DashboardController extends Controller
{
    /** Presets de fecha admitidos por el controlador */
    private const ALLOWED_PRESETS = [
        'today', 'yesterday', 'last_7_days', 'week',
        'last_30_days', 'this_month', 'month', 'prev_month', 'custom'
    ];

    /**
     * Muestra la vista principal del dashboard.
     */
    public function index(Request $request): View
    {
        $tenantId = $this->resolveTenantId();
        $today = $this->today()->toDateString();
        $user = $request->user();

        // 1. Catálogo completo de sucursales visibles para el usuario autenticado
        $allBranches = Branch::forTenant($tenantId)
            ->visibleTo($user)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        // Defensa IDOR: verificar que la sede solicitada pertenece al catálogo visible
        $rawBranchId = $request->filled('branch_id') && is_numeric($request->input('branch_id')) 
            ? (int) $request->input('branch_id') 
            : null;
        $selectedBranchId = ($rawBranchId && $allBranches->contains('id', $rawBranchId))
            ? $rawBranchId
            : null;

        // 2. Rango de fechas sanitizado y blindado contra DoS / formatos inválidos
        [$dateFrom, $dateTo, $preset, $presetLabel] = $this->resolveDateRange($request);

        // 3. Sucursales activas para el mapa Leaflet con conteo de colaboradores
        $branchesQuery = Branch::forTenant($tenantId)
            ->visibleTo($user)
            ->where('is_active', true)
            ->withCount(['employees' => function ($q) {
                $q->where('status', 'active');
            }]);
        if ($selectedBranchId) {
            $branchesQuery->where('id', $selectedBranchId);
        }
        $branches = $branchesQuery->get();

        // Cálculo real de sedes con geocerca calibrada (coordenadas válidas y radio > 0)
        $activeBranchesCount = $branches->count();
        $calibratedBranches = $branches->filter(function ($b) {
            return !is_null($b->latitude) && !is_null($b->longitude) && (int) $b->allowed_radius_meters > 0;
        })->count();
        $calibratedRate = $activeBranchesCount > 0 
            ? (int) round(($calibratedBranches / $activeBranchesCount) * 100) 
            : 100;

        // 4. Métricas / KPIs del período y sede (Consultas agregadas directas en SQL)
        $kpis = $this->calculateDailyKpis($tenantId, $dateFrom, $dateTo, $selectedBranchId, $user);

        // 5. Marcaciones en vivo del período y sede (Paginadas/Limitadas de forma segura)
        $recentAttendancesQuery = Attendance::forTenant($tenantId)
            ->visibleTo($user)
            ->whereBetween('work_date', [$dateFrom, $dateTo])
            ->whereNotNull('clock_in_at')
            ->with(['user:id,first_name,last_name,avatar_path,position', 'branch:id,name,latitude,longitude,allowed_radius_meters'])
            ->orderBy('clock_in_at', 'desc');

        if ($selectedBranchId) {
            $recentAttendancesQuery->where('branch_id', $selectedBranchId);
        }
        $recentAttendances = $recentAttendancesQuery->limit(25)->get();

        // 6. Distribución horaria real completa de TODO el período (no recortada a 20 items)
        $baseAttendanceQuery = Attendance::forTenant($tenantId)
            ->visibleTo($user)
            ->whereBetween('work_date', [$dateFrom, $dateTo]);
        if ($selectedBranchId) {
            $baseAttendanceQuery->where('branch_id', $selectedBranchId);
        }
        $hourlyDistribution = $this->calculateHourlyDistribution($baseAttendanceQuery);
        $hourlyLabels = ['00:00', '02:00', '04:00', '06:00', '08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00', '22:00'];

        // 7. Tendencia semanal optimizada con un único query agregado
        $weeklyChart = $this->getWeeklyTrendData($tenantId, $selectedBranchId, $user);

        $platform = $tenantId === null ? [
            'tenants'        => Tenant::count(),
            'active_tenants' => Tenant::where('status', 'active')->count(),
        ] : null;

        return view('dashboard.index', compact(
            'kpis',
            'branches',
            'allBranches',
            'selectedBranchId',
            'recentAttendances',
            'weeklyChart',
            'hourlyDistribution',
            'hourlyLabels',
            'today',
            'dateFrom',
            'dateTo',
            'preset',
            'presetLabel',
            'platform',
            'calibratedRate'
        ));
    }

    /**
     * Super Admin: elige la empresa a administrar (o vuelve a la vista de plataforma).
     */
    public function switchTenant(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $validated = $request->validate([
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'next'      => ['nullable', 'string', 'max:2000'],
        ]);

        // Desde el selector "¿En qué empresa?": vuelve a la pantalla que se quería abrir
        $target = empty($validated['next']) ? route('dashboard') : SetWebTenant::relativeUrl($validated['next']);

        if (empty($validated['tenant_id'])) {
            $request->session()->forget('admin_tenant_id');

            return redirect()->to($target)->with('success', 'Estás viendo el consolidado de la plataforma.');
        }

        $tenant = Tenant::findOrFail($validated['tenant_id']);
        $request->session()->put('admin_tenant_id', $tenant->id);

        return redirect()->to($target)->with('success', "Ahora administras {$tenant->company_name}.");
    }

    /**
     * Super Admin en la vista global: "¿En qué empresa?" antes de crear o editar.
     * Con una sola empresa activa entra directo en ella.
     */
    public function chooseTenant(Request $request): View|RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $next    = SetWebTenant::relativeUrl($request->query('next'));
        $tenants = SetWebTenant::activeTenants();

        if ($tenants->count() === 1) {
            $request->session()->put('admin_tenant_id', $tenants->first()->id);

            return redirect()->to($next);
        }

        return view('tenant-picker', [
            'tenants' => $tenants,
            'next'    => $next,
            'cancel'  => SetWebTenant::indexForUrl($next),
            'replay'  => null,
        ]);
    }

    /**
     * Endpoint API/JSON para actualización en tiempo real (polling o SSE) del dashboard.
     */
    public function liveStream(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId();
        $user = $request->user();

        // 1. Verificación IDOR de sede en el live stream
        $rawBranchId = $request->filled('branch_id') && is_numeric($request->input('branch_id')) 
            ? (int) $request->input('branch_id') 
            : null;

        $allBranchIds = Branch::forTenant($tenantId)
            ->visibleTo($user)
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $selectedBranchId = ($rawBranchId && in_array($rawBranchId, $allBranchIds, true))
            ? $rawBranchId
            : null;

        // 2. Rango de fechas sanitizado
        [$dateFrom, $dateTo, $preset, $presetLabel] = $this->resolveDateRange($request);

        // 3. KPIs del período
        $kpis = $this->calculateDailyKpis($tenantId, $dateFrom, $dateTo, $selectedBranchId, $user);

        // 4. Marcaciones con coordenadas para pintar pines sobre el mapa
        $attendancesQuery = Attendance::forTenant($tenantId)
            ->visibleTo($user)
            ->whereBetween('work_date', [$dateFrom, $dateTo])
            ->whereNotNull('clock_in_at')
            ->with(['user:id,first_name,last_name,avatar_path,position', 'branch:id,name,latitude,longitude,allowed_radius_meters'])
            ->orderBy('clock_in_at', 'desc');

        if ($selectedBranchId) {
            $attendancesQuery->where('branch_id', $selectedBranchId);
        }

        $rawAttendances = $attendancesQuery->limit(25)->get();

        $attendances = $rawAttendances->map(function ($att) {
            return [
                'id'               => $att->id,
                'user_name'        => "{$att->user->first_name} {$att->user->last_name}",
                'user_position'    => $att->user->position ?? 'Colaborador',
                'avatar_url'       => $att->user->avatar_path ? asset('storage/' . $att->user->avatar_path) : null,
                'branch_name'      => $att->branch->name ?? 'Sin asignar',
                'clock_in_at'      => $att->clock_in_at ? hora($att->clock_in_at, true) : null,
                'clock_out_at'     => $att->clock_out_at ? hora($att->clock_out_at, true) : null,
                'status'           => $att->status,
                'status_label'     => $this->getStatusLabel($att->status),
                'late_minutes'     => $att->late_minutes ?? 0,
                'latitude'         => (float) ($att->clock_in_latitude ?? 0),
                'longitude'        => (float) ($att->clock_in_longitude ?? 0),
                'distance_meters'  => $att->clock_in_distance_meters,
                // null = sin ubicación (plan de contingencia)
                'within_geofence'  => $att->clock_in_within_geofence,
                'photo_url'        => attendance_photo_url($att, 'in'),
                'thumb_url'        => attendance_photo_thumb_url($att, 'in'),
                'is_on_break'      => !is_null($att->break_start_at) && is_null($att->break_end_at),
            ];
        });

        // 5. Distribución horaria real basada en todos los registros del período
        $baseAttendanceQuery = Attendance::forTenant($tenantId)
            ->visibleTo($user)
            ->whereBetween('work_date', [$dateFrom, $dateTo]);
        if ($selectedBranchId) {
            $baseAttendanceQuery->where('branch_id', $selectedBranchId);
        }
        $hourlyDistribution = $this->calculateHourlyDistribution($baseAttendanceQuery);

        // 6. Tendencia semanal optimizada con filtro de sede
        $weeklyChart = $this->getWeeklyTrendData($tenantId, $selectedBranchId, $user);

        // 7. Sedes activas y tasa de calibración
        $branchesQuery = Branch::forTenant($tenantId)
            ->visibleTo($user)
            ->where('is_active', true);
        if ($selectedBranchId) {
            $branchesQuery->where('id', $selectedBranchId);
        }
        $streamBranches = $branchesQuery->get(['id', 'name', 'latitude', 'longitude', 'allowed_radius_meters']);
        $activeBranchesCount = $streamBranches->count();
        $calibratedBranches = $streamBranches->filter(function ($b) {
            return !is_null($b->latitude) && !is_null($b->longitude) && (int) $b->allowed_radius_meters > 0;
        })->count();
        $calibratedRate = $activeBranchesCount > 0 
            ? (int) round(($calibratedBranches / $activeBranchesCount) * 100) 
            : 100;

        return response()->json([
            'success'             => true,
            'timestamp'           => Carbon::now(tenant_timezone())->toIso8601String(),
            'kpis'                => $kpis,
            'attendances'         => $attendances,
            'hourlyDistribution'  => $hourlyDistribution,
            'weeklyChart'         => $weeklyChart,
            'selectedBranchId'    => $selectedBranchId,
            'activeBranchesCount' => $activeBranchesCount,
            'calibratedRate'      => $calibratedRate,
            'dateFrom'            => $dateFrom,
            'dateTo'              => $dateTo,
            'preset'              => $preset,
            'presetLabel'         => $presetLabel,
        ]);
    }

    /**
     * Sanitiza y resuelve el rango de fechas con blindaje contra inyección y DoS.
     *
     * @return array{0: string, 1: string, 2: string, 3: string} [dateFrom, dateTo, preset, presetLabel]
     */
    private function resolveDateRange(Request $request): array
    {
        $today = $this->today()->toDateString();
        $preset = $request->input('preset');

        if (!in_array($preset, self::ALLOWED_PRESETS, true)) {
            $preset = null;
        }

        if ($preset === 'yesterday') {
            $dateFrom = $this->today()->subDay()->toDateString();
            $dateTo = $dateFrom;
            $presetLabel = 'Ayer';
        } elseif ($preset === 'last_7_days' || $preset === 'week') {
            $dateFrom = $this->today()->subDays(6)->toDateString();
            $dateTo = $today;
            $preset = 'week';
            $presetLabel = 'Últimos 7 días';
        } elseif ($preset === 'last_30_days') {
            $dateFrom = $this->today()->subDays(29)->toDateString();
            $dateTo = $today;
            $presetLabel = 'Últimos 30 días';
        } elseif ($preset === 'this_month' || $preset === 'month') {
            $dateFrom = $this->today()->startOfMonth()->toDateString();
            $dateTo = $this->today()->endOfMonth()->toDateString();
            $preset = 'month';
            $presetLabel = 'Este mes';
        } elseif ($preset === 'prev_month') {
            $dateFrom = $this->today()->subMonth()->startOfMonth()->toDateString();
            $dateTo = $this->today()->subMonth()->endOfMonth()->toDateString();
            $presetLabel = 'Mes anterior';
        } elseif ($request->filled('date_from')) {
            $rawFrom = (string) $request->input('date_from');
            $rawTo = (string) $request->input('date_to', $rawFrom);

            try {
                $cFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawFrom) 
                    ? Carbon::createFromFormat('Y-m-d', $rawFrom, tenant_timezone())->startOfDay() 
                    : Carbon::parse($rawFrom, tenant_timezone())->startOfDay();
                $cTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawTo) 
                    ? Carbon::createFromFormat('Y-m-d', $rawTo, tenant_timezone())->startOfDay() 
                    : Carbon::parse($rawTo, tenant_timezone())->startOfDay();
            } catch (\Throwable $e) {
                $cFrom = $this->today();
                $cTo = $this->today();
            }

            // Inversión defensiva si dateFrom > dateTo
            if ($cFrom->gt($cTo)) {
                [$cFrom, $cTo] = [$cTo, $cFrom];
            }

            // Límite defensivo anti-DoS: máximo 366 días (1 año) en un solo análisis de dashboard
            if ($cFrom->diffInDays($cTo) > 366) {
                $cFrom = $cTo->copy()->subDays(365);
            }

            $dateFrom = $cFrom->toDateString();
            $dateTo = $cTo->toDateString();
            $preset = 'custom';
            $presetLabel = $cFrom->format('d/m/Y') . ' - ' . $cTo->format('d/m/Y');
        } elseif ($request->filled('date')) {
            $rawDate = (string) $request->input('date');
            try {
                $cDate = Carbon::parse($rawDate, tenant_timezone())->startOfDay();
            } catch (\Throwable $e) {
                $cDate = $this->today();
            }
            $dateFrom = $cDate->toDateString();
            $dateTo = $dateFrom;
            $preset = 'custom';
            $presetLabel = $cDate->format('d/m/Y');
        } else {
            $dateFrom = $today;
            $dateTo = $today;
            $preset = 'today';
            $presetLabel = 'Hoy';
        }

        return [$dateFrom, $dateTo, $preset, $presetLabel];
    }

    /**
     * Calcula los KPIs consolidados del período y sede mediante agregaciones directas SQL.
     * Escala a millones de registros sin saturación de memoria PHP.
     */
    private function calculateDailyKpis(?int $tenantId, string $dateFrom, string $dateTo, ?int $branchId = null, ?User $viewer = null): array
    {
        $viewer = $viewer ?? Auth::user();

        // 1. Colaboradores activos sujetos a marcación (colaboradores y supervisores)
        $employeesQuery = $this->forTenant(User::query(), $tenantId)
            ->visibleTo($viewer)
            ->whereIn('role', User::CLOCKING_ROLES)
            ->where('status', 'active');

        if ($branchId) {
            $employeesQuery->whereHas('branches', fn($q) => $q->where('branches.id', $branchId));
        }

        $totalEmployees = $employeesQuery->count();

        // 2. Consulta de asistencias para el período y sede
        $attendancesQuery = $this->forTenant(Attendance::query(), $tenantId)
            ->visibleTo($viewer)
            ->whereBetween('work_date', [$dateFrom, $dateTo]);

        if ($branchId) {
            $attendancesQuery->where('branch_id', $branchId);
        }

        // Agregación directa ANSI-SQL de alta concurrencia
        $stats = (clone $attendancesQuery)
            ->selectRaw("
                COUNT(CASE WHEN clock_in_at IS NOT NULL THEN 1 END) as total_attendances,
                COUNT(CASE WHEN status = 'present' AND (late_minutes IS NULL OR late_minutes = 0) THEN 1 END) as on_time_count,
                COUNT(CASE WHEN status = 'late' OR (late_minutes IS NOT NULL AND late_minutes > 0) THEN 1 END) as late_count,
                COUNT(CASE WHEN break_start_at IS NOT NULL AND break_end_at IS NULL THEN 1 END) as on_break_count,
                COUNT(CASE WHEN clock_in_at IS NOT NULL AND clock_out_at IS NULL THEN 1 END) as active_on_shift,
                COUNT(CASE WHEN clock_in_at IS NOT NULL AND (clock_in_within_geofence = 0 OR clock_in_within_geofence IS FALSE) THEN 1 END) as out_of_geofence,
                COUNT(CASE WHEN clock_in_at IS NOT NULL AND (clock_in_within_geofence = 1 OR clock_in_within_geofence IS TRUE) THEN 1 END) as within_geofence_count
            ")
            ->first();

        $totalAttendances = (int) ($stats->total_attendances ?? 0);
        $onTimeCount = (int) ($stats->on_time_count ?? 0);
        $lateCount = (int) ($stats->late_count ?? 0);
        $onBreakCount = (int) ($stats->on_break_count ?? 0);
        $activeOnShift = (int) ($stats->active_on_shift ?? 0);
        $outOfGeofenceCount = (int) ($stats->out_of_geofence ?? 0);
        $withinGeofenceCount = (int) ($stats->within_geofence_count ?? 0);

        // 3. Cálculos matemáticos rigurosos (Cero duplicación de métricas)
        $days = max(1, Carbon::parse($dateFrom)->diffInDays(Carbon::parse($dateTo)) + 1);
        $expectedTotal = $totalEmployees * $days;
        $absentCount = max(0, $expectedTotal - $totalAttendances);

        // Tasa de puntualidad: Porcentaje de asistentes que llegaron a tiempo
        $punctualityRate = $totalAttendances > 0 
            ? min(100.0, max(0.0, round(($onTimeCount / $totalAttendances) * 100, 1)))
            : 100.0;

        // Tasa de cobertura de turnos
        $attendanceRate = $expectedTotal > 0 
            ? min(100.0, max(0.0, round(($totalAttendances / $expectedTotal) * 100, 1))) 
            : ($totalEmployees > 0 ? min(100.0, max(0.0, round(($totalAttendances / $totalEmployees) * 100, 1))) : 0.0);

        $coverageRate = $attendanceRate;

        // Cumplimiento de geocercas GPS
        $geofenceRate = $totalAttendances > 0 
            ? min(100.0, max(0.0, round(($withinGeofenceCount / $totalAttendances) * 100, 1))) 
            : 100.0;

        return [
            'total_employees'       => $totalEmployees,
            'total_attendances'     => $totalAttendances,
            'present_count'         => $totalAttendances, // Retrocompatibilidad
            'on_time_count'         => $onTimeCount,
            'late_count'            => $lateCount,
            'on_break_count'        => $onBreakCount,
            'active_on_shift'       => $activeOnShift,
            'absent_count'          => $absentCount,
            'out_of_geofence'       => $outOfGeofenceCount,
            'within_geofence_count' => $withinGeofenceCount,
            'punctuality_rate'      => $punctualityRate,
            'geofence_rate'         => $geofenceRate,
            'coverage_rate'         => $coverageRate,
            'attendance_rate'       => $attendanceRate,
        ];
    }

    /**
     * Calcula la distribución horaria completa de todas las marcaciones del período.
     */
    private function calculateHourlyDistribution(Builder $attendancesQuery): array
    {
        $hourlyDistribution = array_fill(0, 12, 0); // 12 ranuras de 2h
        
        $clockInTimes = (clone $attendancesQuery)
            ->whereNotNull('clock_in_at')
            ->pluck('clock_in_at');

        foreach ($clockInTimes as $time) {
            if ($time) {
                $cTime = $time instanceof Carbon ? $time : Carbon::parse($time);
                $slot = min(11, (int) floor(((int) $cTime->format('G')) / 2));
                $hourlyDistribution[$slot]++;
            }
        }

        return $hourlyDistribution;
    }

    /**
     * Obtiene los datos agrupados de los últimos 7 días para el gráfico con filtro de sede.
     * Optimizado para ejecutar una sola consulta agrupada en lugar de 7 independientes.
     */
    private function getWeeklyTrendData(?int $tenantId, ?int $branchId = null, ?User $viewer = null): array
    {
        $viewer = $viewer ?? Auth::user();
        $labels = [];
        $daysMap = [];

        for ($i = 6; $i >= 0; $i--) {
            $day = $this->today()->subDays($i);
            $dayStr = $day->toDateString();
            $labels[] = $day->isoFormat('ddd D');
            $daysMap[$dayStr] = [
                'on_time' => 0,
                'late'    => 0,
                'absent'  => 0,
            ];
        }

        $startDate = $this->today()->subDays(6)->toDateString();
        $endDate = $this->today()->toDateString();

        $query = $this->forTenant(Attendance::query(), $tenantId)
            ->visibleTo($viewer)
            ->whereBetween('work_date', [$startDate, $endDate]);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $records = $query->select('work_date', 'status', 'late_minutes')->get();

        foreach ($records as $r) {
            $d = $r->work_date ? ($r->work_date instanceof Carbon ? $r->work_date->toDateString() : substr((string) $r->work_date, 0, 10)) : null;
            if ($d && isset($daysMap[$d])) {
                if ($r->status === 'present' && ($r->late_minutes ?? 0) == 0) {
                    $daysMap[$d]['on_time']++;
                } elseif ($r->status === 'late' || ($r->late_minutes ?? 0) > 0) {
                    $daysMap[$d]['late']++;
                } elseif ($r->status === 'absent') {
                    $daysMap[$d]['absent']++;
                }
            }
        }

        $onTimeData = [];
        $lateData = [];
        $absentData = [];

        foreach ($daysMap as $dayCounts) {
            $onTimeData[] = $dayCounts['on_time'];
            $lateData[] = $dayCounts['late'];
            $absentData[] = $dayCounts['absent'];
        }

        return [
            'labels'  => $labels,
            'on_time' => $onTimeData,
            'late'    => $lateData,
            'absent'  => $absentData,
        ];
    }

    /**
     * Empresa activa del TenantContext. Null solo para el Super Admin sin
     * empresa elegida; cualquier otro usuario sin empresa queda fuera.
     */
    private function resolveTenantId(): ?int
    {
        $context = app(TenantContext::class);

        if ($context->resolved()) {
            return $context->id();
        }

        abort_unless(Auth::user()?->isSuperAdmin(), 403);

        return null;
    }

    /**
     * "Hoy" en la zona horaria de la empresa activa; en la vista global del
     * Super Admin, en la de la aplicación (APP_TIMEZONE).
     */
    private function today(): Carbon
    {
        return Carbon::now(tenant_timezone())->startOfDay();
    }

    /**
     * Filtra por empresa. Sin empresa (vista global del Super Admin) abarca
     * todas las empresas.
     */
    private function forTenant(Builder $query, ?int $tenantId): Builder
    {
        return $tenantId === null ? $query : $query->where('tenant_id', $tenantId);
    }

    /**
     * Retorna la etiqueta legible para un estado de asistencia.
     */
    private function getStatusLabel(string $status): string
    {
        return \App\Modules\Core\Support\Labels::enum('attendance_status', $status);
    }
}
