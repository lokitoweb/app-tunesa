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
    public function index(Request $request): View
    {
        $tenantId  = current_tenant_id_or_null();
        $startDate = $this->date($request->input('start_date'), Carbon::today()->subDays(6));
        $endDate   = $this->date($request->input('end_date'), Carbon::today());
        if ($endDate->lt($startDate)) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $base = AttendancePunchAttempt::forTenant($tenantId)
            ->whereBetween('created_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()])
            ->when($request->input('branch_id'), fn ($q, $id) => $q->where('branch_id', $id))
            ->when($request->input('search'), fn ($q, $search) => $q->whereHas('user', function ($u) use ($search) {
                $u->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('employee_code', 'like', "%{$search}%")
                  ->orWhere('document_number', 'like', "%{$search}%");
            }));

        $byCode = (clone $base)->selectRaw('error_code, count(*) as total')->groupBy('error_code')->pluck('total', 'error_code');

        $attempts = (clone $base)
            ->when($request->input('code'), fn ($q, $code) => $q->where('error_code', $code))
            ->with(['user:id,first_name,last_name,employee_code,tenant_id', 'branch:id,name'])
            ->latest('created_at')->latest('id')
            ->paginate(25)->withQueryString();

        $branches = Branch::forTenant($tenantId)->orderBy('name')
            ->get(['id', 'name', 'latitude', 'longitude', 'allowed_radius_meters', 'geofence_tolerance_meters']);

        return view('attendance.attempts', [
            'attempts'  => $attempts,
            'branches'  => $branches,
            // Mapa: las sedes con su radio y los intentos de esta página que trajeron coordenadas
            'mapBranches' => $branches->filter(fn ($b) => $b->latitude !== null && $b->longitude !== null)
                ->when($request->input('branch_id'), fn ($c, $id) => $c->where('id', (int) $id))
                ->map(fn ($b) => [
                    'name' => $b->name, 'lat' => (float) $b->latitude, 'lng' => (float) $b->longitude,
                    'radius' => (int) $b->allowed_radius_meters, 'tolerance' => $b->edgeToleranceMeters(),
                ])->values(),
            'mapPoints' => $attempts->getCollection()->filter(fn ($a) => $a->latitude !== null && $a->longitude !== null)
                ->map(fn ($a) => [
                    'lat' => (float) $a->latitude, 'lng' => (float) $a->longitude,
                    'name' => trim(($a->user->first_name ?? '') . ' ' . ($a->user->last_name ?? '')),
                    'code' => label('punch_attempt_code', $a->error_code),
                    'at' => $a->created_at->format('d/m/Y h:i a'),
                    'mock' => (bool) $a->mock_location_detected,
                ])->values(),
            'codes'     => Labels::options('punch_attempt_code'),
            'byCode'    => $byCode,
            'total'     => $byCode->sum(),
            'startDate' => $startDate->toDateString(),
            'endDate'   => $endDate->toDateString(),
        ]);
    }

    private function date(mixed $value, Carbon $default): Carbon
    {
        try {
            return is_string($value) && $value !== '' ? Carbon::createFromFormat('Y-m-d', $value)->startOfDay() : $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
