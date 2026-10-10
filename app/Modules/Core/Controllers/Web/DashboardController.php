<?php

namespace App\Modules\Core\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
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
    /**
     * Muestra la vista principal del dashboard.
     */
    public function index(Request $request): View
    {
        $tenantId = $this->resolveTenantId();
        $today = $this->today()->toDateString();

        // 1. Métricas / KPIs del día
        $kpis = $this->calculateDailyKpis($tenantId, $today);

        // 2. Sucursales activas con geocercas y 3. marcaciones recientes:
        //    con $tenantId null (vista global del Super Admin) son de todas las empresas.
        $branches = Branch::forTenant($tenantId)
            ->visibleTo($request->user())
            ->where('is_active', true)
            ->withCount(['employees' => function ($q) {
                $q->where('status', 'active');
            }])
            ->get();

        $recentAttendances = Attendance::forTenant($tenantId)
            ->visibleTo($request->user())
            ->whereDate('work_date', $today)
            ->whereNotNull('clock_in_at')
            ->with(['user', 'branch'])
            ->orderBy('clock_in_at', 'desc')
            ->limit(12)
            ->get();

        // 4. Datos de puntualidad de los últimos 7 días para Chart.js
        $weeklyChart = $this->getWeeklyTrendData($tenantId);

        $platform = $tenantId === null ? [
            'tenants'        => Tenant::count(),
            'active_tenants' => Tenant::where('status', 'active')->count(),
        ] : null;

        return view('dashboard.index', compact(
            'kpis',
            'branches',
            'recentAttendances',
            'weeklyChart',
            'today',
            'platform'
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
        ]);

        if (empty($validated['tenant_id'])) {
            $request->session()->forget('admin_tenant_id');

            return redirect()->route('dashboard')->with('success', 'Estás viendo el consolidado de la plataforma.');
        }

        $tenant = Tenant::findOrFail($validated['tenant_id']);
        $request->session()->put('admin_tenant_id', $tenant->id);

        return redirect()->route('dashboard')->with('success', "Ahora administras {$tenant->company_name}.");
    }

    /**
     * Endpoint API/JSON para actualización en tiempo real (polling o SSE) del mapa.
     */
    public function liveStream(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId();
        $today = $this->today()->toDateString();

        $kpis = $this->calculateDailyKpis($tenantId, $today);

        // Marcaciones del día con coordenadas para pintar pines sobre el mapa
        $attendances = Attendance::forTenant($tenantId)
            ->visibleTo($request->user())
            ->whereDate('work_date', $today)
            ->whereNotNull('clock_in_at')
            ->with(['user:id,first_name,last_name,avatar_path,position', 'branch:id,name,latitude,longitude,allowed_radius_meters'])
            ->orderBy('clock_in_at', 'desc')
            ->limit(30)
            ->get()
            ->map(function ($att) {
                return [
                    'id'               => $att->id,
                    'user_name'        => "{$att->user->first_name} {$att->user->last_name}",
                    'user_position'    => $att->user->position ?? 'Colaborador',
                    'avatar_url'       => $att->user->avatar_path ? asset('storage/' . $att->user->avatar_path) : null,
                    'branch_name'      => $att->branch->name ?? 'Sin asignar',
                    'clock_in_at'      => $att->clock_in_at ? $att->clock_in_at->format('H:i:s') : null,
                    'clock_out_at'     => $att->clock_out_at ? $att->clock_out_at->format('H:i:s') : null,
                    'status'           => $att->status,
                    'status_label'     => $this->getStatusLabel($att->status),
                    'late_minutes'     => $att->late_minutes ?? 0,
                    'latitude'         => (float) ($att->clock_in_latitude ?? 0),
                    'longitude'        => (float) ($att->clock_in_longitude ?? 0),
                    'distance_meters'  => $att->clock_in_distance_meters,
                    'within_geofence'  => (bool) $att->clock_in_within_geofence,
                    'photo_url'        => attendance_photo_url($att, 'in'),
                    'is_on_break'      => !is_null($att->break_start_at) && is_null($att->break_end_at),
                ];
            });

        return response()->json([
            'success'     => true,
            'timestamp'   => Carbon::now(tenant_timezone())->toIso8601String(),
            'kpis'        => $kpis,
            'attendances' => $attendances,
        ]);
    }

    /**
     * Calcula los KPIs consolidados del día.
     */
    private function calculateDailyKpis(?int $tenantId, string $date): array
    {
        $totalEmployees = $this->forTenant(User::query(), $tenantId)
            ->visibleTo(Auth::user())
            ->where('role', 'employee')
            ->where('status', 'active')
            ->count();

        $todayAttendances = $this->forTenant(Attendance::query(), $tenantId)
            ->visibleTo(Auth::user())
            ->whereDate('work_date', $date)
            ->get();

        $presentCount = $todayAttendances->whereNotNull('clock_in_at')->count();

        $lateCount = $todayAttendances->filter(function ($a) {
            return $a->status === 'late' || ($a->late_minutes ?? 0) > 0;
        })->count();

        $onTimeCount = $todayAttendances->filter(function ($a) {
            return $a->status === 'present';
        })->count();

        $onBreakCount = $todayAttendances->filter(function ($a) {
            return !is_null($a->break_start_at) && is_null($a->break_end_at);
        })->count();

        $outOfGeofenceCount = $todayAttendances->filter(function ($a) {
            return $a->clock_in_within_geofence === false;
        })->count();

        $absentCount = max(0, $totalEmployees - $presentCount);

        $attendanceRate = $totalEmployees > 0 
            ? round(($presentCount / $totalEmployees) * 100, 1) 
            : 0;

        return [
            'total_employees'     => $totalEmployees,
            'present_count'       => $presentCount,
            'late_count'          => $lateCount,
            'on_time_count'       => $onTimeCount,
            'on_break_count'      => $onBreakCount,
            'absent_count'        => $absentCount,
            'out_of_geofence'     => $outOfGeofenceCount,
            'attendance_rate'     => $attendanceRate,
        ];
    }

    /**
     * Obtiene los datos agrupados de los últimos 7 días para el gráfico.
     */
    private function getWeeklyTrendData(?int $tenantId): array
    {
        $labels = [];
        $onTimeData = [];
        $lateData = [];
        $absentData = [];

        for ($i = 6; $i >= 0; $i--) {
            $day = $this->today()->subDays($i);
            $dayStr = $day->toDateString();
            $labels[] = $day->isoFormat('ddd D');

            $records = $this->forTenant(Attendance::query(), $tenantId)
                ->visibleTo(Auth::user())
                ->whereDate('work_date', $dayStr)
                ->get();

            $onTimeData[] = $records->where('status', 'present')->count();
            $lateData[] = $records->filter(fn($r) => $r->status === 'late' || ($r->late_minutes ?? 0) > 0)->count();
            $absentData[] = $records->where('status', 'absent')->count();
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
