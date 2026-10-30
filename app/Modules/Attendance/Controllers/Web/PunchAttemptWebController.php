<?php

namespace App\Modules\Attendance\Controllers\Web;

use App\Modules\Attendance\Models\AttendancePunchAttempt;
use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Support\Labels;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Intentos de marcación rechazados: fuera de la geocerca, ubicación simulada,
 * GPS impreciso... RRHH ve quién intentó marcar, desde dónde y con qué teléfono.
 */
class PunchAttemptWebController extends Controller
{
    /** Motivos con tarjeta propia; el resto suma en "Otros" (Total = suma de las tarjetas) */
    public const MAIN_CODES = ['MOCK_LOCATION_DETECTED', 'GEOFENCE_VIOLATION', 'GPS_ACCURACY_TOO_LOW'];
    public const OTHER_CODE = 'OTHER';

    public function index(Request $request): View
    {
        $tenantId  = current_tenant_id_or_null();
        // El rango es en días de la empresa: "Hasta hoy" incluye hasta las 23:59 de su zona horaria
        $tz        = tenant_timezone();
        $today     = Carbon::now($tz)->startOfDay();
        $startDate = $this->date($request->input('start_date'), $today->copy()->subDays(6), $tz);
        $endDate   = $this->date($request->input('end_date'), $today, $tz);
        if ($endDate->lt($startDate)) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }
        $appTz = config('app.timezone');

        $base = AttendancePunchAttempt::forTenant($tenantId)
            ->whereBetween('created_at', [$startDate->copy()->startOfDay()->setTimezone($appTz), $endDate->copy()->endOfDay()->setTimezone($appTz)])
            ->when($request->input('branch_id'), fn ($q, $id) => $q->where('branch_id', $id))
            ->when(in_array($request->input('type'), ['clock_in', 'break_start', 'break_end', 'clock_out'], true), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->input('search'), fn ($q, $search) => $q->whereHas('user', function ($u) use ($search) {
                $u->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('employee_code', 'like', "%{$search}%")
                  ->orWhere('document_number', 'like', "%{$search}%");
            }));

        $byCode = (clone $base)->selectRaw('error_code, count(*) as total')->groupBy('error_code')->pluck('total', 'error_code');
        $total = $byCode->sum();
        // Las tarjetas cuentan por motivo (error_code), igual que la lista, para que siempre sumen el Total
        $mockCount = (int) ($byCode['MOCK_LOCATION_DETECTED'] ?? 0);
        $otherCount = $total - $byCode->only(self::MAIN_CODES)->sum();
        $appCount = (clone $base)->where('source', AttendancePunchAttempt::SOURCE_APP)->count();
        $uniqueUsersCount = (clone $base)->distinct('user_id')->count('user_id');

        $perPage = in_array((int) $request->input('per_page'), [10, 25, 50, 100], true) ? (int) $request->input('per_page') : 25;

        $attempts = (clone $base)
            ->when($request->input('code'), fn ($q, $code) => $code === self::OTHER_CODE
                ? $q->whereNotIn('error_code', self::MAIN_CODES)
                : $q->where('error_code', $code))
            ->with(['user:id,first_name,last_name,employee_code,avatar_path,position,document_number,tenant_id', 'branch:id,name'])
            ->latest('created_at')->latest('id')
            ->paginate($perPage)->withQueryString();

        $branches = Branch::forTenant($tenantId)->orderBy('name')
            ->get(['id', 'tenant_id', 'name', 'latitude', 'longitude', 'allowed_radius_meters', 'geofence_tolerance_meters', 'geofence_policy']);

        // Sedes que aceptan la marcación fuera de la geocerca (la marcan en vez de rechazarla):
        // esas no llegan aquí, se ven en Marcaciones y GPS como "Fuera de ubicación"
        $flaggingBranches = $branches->filter(fn ($b) => $b->flagsOutOfGeofence())
            ->when($request->input('branch_id'), fn ($c, $id) => $c->where('id', (int) $id))
            ->pluck('name');

        return view('attendance.attempts', [
            'attempts'         => $attempts,
            'branches'         => $branches,
            // Mapa: las sedes con su radio y los intentos de esta página que trajeron coordenadas
            'mapBranches'      => $branches->filter(fn ($b) => $b->latitude !== null && $b->longitude !== null)
                ->when($request->input('branch_id'), fn ($c, $id) => $c->where('id', (int) $id))
                ->map(fn ($b) => [
                    'name'      => $b->name,
                    'lat'       => (float) $b->latitude,
                    'lng'       => (float) $b->longitude,
                    'radius'    => (int) $b->allowed_radius_meters,
                    'tolerance' => $b->edgeToleranceMeters(),
                ])->values(),
            'mapPoints'        => $attempts->getCollection()->filter(fn ($a) => $a->latitude !== null && $a->longitude !== null)
                ->map(fn ($a) => [
                    'id'       => $a->id,
                    'lat'      => (float) $a->latitude,
                    'lng'      => (float) $a->longitude,
                    'name'     => trim(($a->user->first_name ?? '') . ' ' . ($a->user->last_name ?? '')),
                    'code'     => label('punch_attempt_code', $a->error_code),
                    'code_raw' => $a->error_code,
                    'at'       => $a->created_at->copy()->setTimezone($tz)->format('d/m/Y h:i a'),
                    'mock'     => (bool) $a->mock_location_detected,
                    'distance' => $a->distance_meters,
                    'allowed'  => $a->allowed_radius,
                    'accuracy' => $a->accuracy_meters,
                ])->values(),
            'codes'            => Labels::options('punch_attempt_code') + [self::OTHER_CODE => 'Otros motivos'],
            'byCode'           => $byCode,
            'total'            => $total,
            'mockCount'        => $mockCount,
            'otherCount'       => $otherCount,
            'flaggingBranches' => $flaggingBranches,
            'tz'               => $tz,
            'appCount'         => $appCount,
            'uniqueUsersCount' => $uniqueUsersCount,
            'startDate'        => $startDate->toDateString(),
            'endDate'          => $endDate->toDateString(),
        ]);
    }

    private function date(mixed $value, Carbon $default, string $tz): Carbon
    {
        try {
            return is_string($value) && $value !== '' ? Carbon::createFromFormat('Y-m-d', $value, $tz)->startOfDay() : $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
