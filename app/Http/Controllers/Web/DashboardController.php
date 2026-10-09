<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * DashboardController (Web)
 *
 * Administra el cuadro de mando en tiempo real con estadísticas operativas,
 * mapa interactivo Leaflet de geocercas y feed en vivo de marcaciones.
 */
class DashboardController extends Controller
{
    /**
     * Muestra la vista principal del dashboard.
     */
    public function index(Request $request): View
    {
        $tenantId = Auth::user()->tenant_id;
        $today = Carbon::today()->toDateString();

        // 1. Métricas / KPIs del día
        $kpis = $this->calculateDailyKpis($tenantId, $today);

        // 2. Sucursales activas con geocercas para el mapa Leaflet
        $branches = Branch::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->withCount(['employees' => function ($q) {
                $q->where('status', 'active');
            }])
            ->get();

        // 3. Marcaciones recientes del día con detalles y fotos
        $recentAttendances = Attendance::where('tenant_id', $tenantId)
            ->whereDate('work_date', $today)
            ->whereNotNull('clock_in_at')
            ->with(['user', 'branch'])
            ->orderBy('clock_in_at', 'desc')
            ->limit(12)
            ->get();

        // 4. Datos de puntualidad de los últimos 7 días para Chart.js
        $weeklyChart = $this->getWeeklyTrendData($tenantId);

        return view('dashboard.index', compact(
            'kpis',
            'branches',
            'recentAttendances',
            'weeklyChart',
            'today'
        ));
    }

    /**
     * Endpoint API/JSON para actualización en tiempo real (polling o SSE) del mapa.
     */
    public function liveStream(Request $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $today = Carbon::today()->toDateString();

        $kpis = $this->calculateDailyKpis($tenantId, $today);

        // Marcaciones del día con coordenadas para pintar pines sobre el mapa
        $attendances = Attendance::where('tenant_id', $tenantId)
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
                    'photo_url'        => $att->clock_in_photo ? asset('storage/' . $att->clock_in_photo) : null,
                    'is_on_break'      => !is_null($att->break_start_at) && is_null($att->break_end_at),
                ];
            });

        return response()->json([
            'success'     => true,
            'timestamp'   => Carbon::now()->toIso8601String(),
            'kpis'        => $kpis,
            'attendances' => $attendances,
        ]);
    }

    /**
     * Calcula los KPIs consolidados del día.
     */
    private function calculateDailyKpis(int $tenantId, string $date): array
    {
        $totalEmployees = User::where('tenant_id', $tenantId)
            ->where('role', 'employee')
            ->where('status', 'active')
            ->count();

        $todayAttendances = Attendance::where('tenant_id', $tenantId)
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
    private function getWeeklyTrendData(int $tenantId): array
    {
        $labels = [];
        $onTimeData = [];
        $lateData = [];
        $absentData = [];

        for ($i = 6; $i >= 0; $i--) {
            $day = Carbon::today()->subDays($i);
            $dayStr = $day->toDateString();
            $labels[] = $day->isoFormat('ddd D');

            $records = Attendance::where('tenant_id', $tenantId)
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
     * Retorna la etiqueta legible para un estado de asistencia.
     */
    private function getStatusLabel(string $status): string
    {
        return match ($status) {
            'present'    => 'Puntual',
            'late'       => 'Tardanza',
            'absent'     => 'Ausente',
            'excused'    => 'Justificado',
            'holiday'    => 'Feriado',
            'day_off'    => 'Descanso',
            default      => ucfirst($status),
        };
    }
}
