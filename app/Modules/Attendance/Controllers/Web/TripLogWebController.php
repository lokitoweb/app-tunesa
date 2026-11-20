<?php

namespace App\Modules\Attendance\Controllers\Web;

use App\Modules\Attendance\Models\TripLog;
use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Bitácora de viajes (requerimiento Tunesa): el supervisor registra la IDA de cada viaje
 * (fecha, ruta, chofer, ayudante, placa, hora de salida) y la VUELTA (fecha, hora, ruta), que
 * queda firmada con su usuario y la hora. Los choferes no marcan: un día con viaje es día
 * trabajado y se compara con los días de trabajo del mes (Configuración › Reglas, 26 / 4).
 */
class TripLogWebController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $now  = CarbonImmutable::now(tenant_timezone());
        $data = $request->validate([
            'from'      => ['nullable', 'date_format:Y-m-d'],
            'to'        => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'driver_id' => ['nullable', 'integer'],
        ]);
        $from = $data['from'] ?? $now->startOfMonth()->toDateString();
        $to   = $data['to'] ?? $now->endOfMonth()->toDateString();

        $people = $this->people($request);
        $ids    = $people->pluck('id')->all();

        $trips = TripLog::with(['driver:id,first_name,last_name', 'helper:id,first_name,last_name', 'signer:id,first_name,last_name', 'branch:id,name'])
            ->whereBetween('trip_date', [$from, $to])
            ->ofPeople($ids)
            ->when($data['driver_id'] ?? null, fn ($q, $id) => $q->ofPeople([(int) $id]))
            ->orderByDesc('trip_date')->orderByDesc('departure_time')
            ->paginate(25)->withQueryString();

        $settings = TenantSetting::forTenant(current_tenant_id_or_null() ? current_tenant() : null);
        $worked   = TripLog::workedDays($ids, $from, $to);

        return view('attendance.trips', [
            'trips'     => $trips,
            'people'    => $people,
            'summary'   => $people->map(fn (User $u) => ['user' => $u, 'days' => $worked[$u->id] ?? 0])->sortByDesc('days')->values(),
            'workDays'  => (int) $settings->trip_work_days,
            'restDays'  => (int) $settings->trip_rest_days,
            'branches'  => Branch::visibleTo($request->user())->orderBy('name')->get(['id', 'name']),
            'from'      => $from,
            'to'        => $to,
            'driverId'  => $data['driver_id'] ?? null,
            'today'     => $now->toDateString(),
            'hasTenant' => (bool) current_tenant_id_or_null(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(current_tenant_id_or_null(), 422, 'Elige una empresa en el selector superior.');
        $data = $this->validated($request);

        $trip = TripLog::create($data + ['tenant_id' => current_tenant_id_or_null(), 'created_by' => $request->user()->id]
            + $this->signature($request, $data));
        $this->audit->log('attendance.trip_created', $trip, $trip->only(['trip_date', 'route', 'driver_id', 'helper_id', 'vehicle_plate', 'departure_time']));
        if ($trip->hasReturned()) {
            $this->audit->log('attendance.trip_returned', $trip, $trip->only(['return_date', 'return_time', 'return_route']));
        }

        return back()->with('success', 'Viaje registrado' . ($trip->hasReturned() ? ' con su vuelta firmada.' : '. Registra la vuelta cuando regrese.'));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $trip = $this->findVisible($request, $id);
        $data = $this->validated($request, $trip);
        $returning = ! $trip->hasReturned() && ! empty($data['return_time']);

        $this->audit->updateWithAudit('attendance.trip_updated', $trip, $data + ($returning ? $this->signature($request, $data) : []));
        if ($returning) {
            $this->audit->log('attendance.trip_returned', $trip, $trip->only(['return_date', 'return_time', 'return_route']));
        }

        return back()->with('success', $returning ? 'Vuelta registrada y firmada.' : 'Viaje actualizado.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $trip = $this->findVisible($request, $id);
        $this->audit->log('attendance.trip_deleted', $trip, $trip->only(['trip_date', 'route', 'driver_id', 'vehicle_plate']));
        $trip->delete();

        return back()->with('success', 'Viaje eliminado de la bitácora.');
    }

    /** IDA obligatoria; la VUELTA (fecha y hora) se registra al regresar, juntas */
    private function validated(Request $request, ?TripLog $trip = null): array
    {
        $ids = $this->people($request)->pluck('id')->all();

        $data = $request->validate([
            'trip_date'      => ['required', 'date_format:Y-m-d'],
            'route'          => ['required', 'string', 'max:150'],
            'driver_id'      => ['required', 'integer', Rule::in($ids)],
            'helper_id'      => ['nullable', 'integer', 'different:driver_id', Rule::in($ids)],
            'vehicle_plate'  => ['required', 'string', 'max:15', 'regex:/^[A-Za-z0-9-]+$/'],
            'departure_time' => ['required', 'date_format:H:i'],
            'branch_id'      => ['nullable', 'integer', Rule::in(Branch::visibleTo($request->user())->pluck('id')->all())],
            'return_date'    => ['nullable', 'required_with:return_time', 'date_format:Y-m-d', 'after_or_equal:trip_date'],
            'return_time'    => ['nullable', 'required_with:return_date', 'date_format:H:i'],
            'return_route'   => ['nullable', 'string', 'max:150'],
            'notes'          => ['nullable', 'string', 'max:300'],
        ], [
            'driver_id.in'                 => 'Elige un chofer con cargo de «Control por viajes» de tu equipo.',
            'helper_id.in'                 => 'Elige un ayudante con cargo de «Control por viajes» de tu equipo.',
            'helper_id.different'          => 'El ayudante no puede ser el mismo chofer.',
            'vehicle_plate.regex'          => 'La placa solo lleva letras, números y guiones.',
            'return_date.after_or_equal'   => 'La vuelta no puede ser antes de la ida.',
            'return_date.required_with'    => 'Indica la fecha de la vuelta.',
            'return_time.required_with'    => 'Indica la hora de la vuelta.',
        ]);
        $data['vehicle_plate'] = strtoupper($data['vehicle_plate']);
        if (! empty($data['return_date']) && $data['return_date'] === $data['trip_date'] && $data['return_time'] < $data['departure_time']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['return_time' => 'La hora de vuelta es anterior a la salida del mismo día.']);
        }

        return $data;
    }

    /** Firma de la vuelta: quién la registra y cuándo (solo si se registró la vuelta) */
    private function signature(Request $request, array $data): array
    {
        return empty($data['return_time']) ? [] : ['return_signed_by' => $request->user()->id, 'return_signed_at' => now()];
    }

    private function findVisible(Request $request, int $id): TripLog
    {
        return TripLog::ofPeople($this->people($request)->pluck('id')->all())->findOrFail($id);
    }

    private ?\Illuminate\Support\Collection $people = null;

    /** Choferes y ayudantes activos (cargo con «Control por viajes») que quien registra puede ver; una consulta por petición */
    private function people(Request $request): \Illuminate\Support\Collection
    {
        if (! current_tenant_id_or_null()) {
            return collect();
        }

        return $this->people ??= User::where('status', 'active')->tripControlled()
            ->when(! $request->user()->hasPermission('attendance.view_all'), fn ($q) => $q->visibleTo($request->user()))
            ->orderBy('last_name')->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'employee_code', 'position']);
    }
}
