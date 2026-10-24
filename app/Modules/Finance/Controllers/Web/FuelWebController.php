<?php

namespace App\Modules\Finance\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Finance\Models\FuelLoad;
use App\Modules\Finance\Models\Supplier;
use App\Modules\Finance\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Control de combustible por placa: cargas, kilometraje y rendimiento (km/galón) */
class FuelWebController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? $request->query('month') : now(tenant_timezone())->format('Y-m');
        $vehicleId = $request->integer('vehicle') ?: null;
        $start = Carbon::parse("{$month}-01");

        $loads = FuelLoad::with(['vehicle:id,plate', 'supplier:id,name'])
            ->whereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateTimeString()])
            ->when($vehicleId, fn ($q) => $q->where('vehicle_id', $vehicleId))
            ->orderBy('vehicle_id')->orderBy('odometer')->get();

        // Rendimiento: km recorridos desde la carga anterior del mismo vehículo / galones de esta carga
        $previous = FuelLoad::whereIn('vehicle_id', $loads->pluck('vehicle_id')->unique())->where('date', '<', $start->toDateString())
            ->selectRaw('vehicle_id, max(odometer) as odometer')->groupBy('vehicle_id')->pluck('odometer', 'vehicle_id');
        $last = [];
        $rows = $loads->map(function (FuelLoad $l) use (&$last, $previous) {
            $before = $last[$l->vehicle_id] ?? $previous[$l->vehicle_id] ?? null;
            $last[$l->vehicle_id] = $l->odometer;
            $km = $before !== null ? $l->odometer - (int) $before : null;

            return [
                ...$l->only(['id', 'vehicle_id', 'fuel_type', 'document_number', 'driver', 'supplier_id', 'odometer']),
                'date' => $l->date->toDateString(), 'date_label' => $l->date->format('d/m/Y'), 'plate' => $l->vehicle?->plate,
                'supplier' => $l->supplier?->name, 'gallons' => (float) $l->gallons, 'price_per_gallon' => (float) $l->price_per_gallon,
                'total' => (float) $l->total, 'km' => $km, 'km_per_gallon' => $km !== null && (float) $l->gallons > 0 ? round($km / (float) $l->gallons, 2) : null,
            ];
        })->sortByDesc('date')->values();

        $byVehicle = $rows->groupBy('plate')->map(fn ($r) => [
            'gallons' => round($r->sum('gallons'), 3), 'total' => round($r->sum('total'), 2), 'km' => $r->sum('km'),
            'km_per_gallon' => $r->sum('gallons') > 0 && $r->whereNotNull('km')->count() ? round($r->sum('km') / $r->whereNotNull('km')->sum('gallons'), 2) : null,
        ]);

        return Inertia::render('Finance/Pages/Fuel/Index', [
            'loads'      => $rows,
            'by_vehicle' => $byVehicle,
            'filters'    => ['month' => $month, 'vehicle' => $vehicleId],
            'vehicles'   => Vehicle::where('is_active', true)->orderBy('plate')->get(['id', 'plate', 'type']),
            'suppliers'  => Supplier::where('is_active', true)->orderBy('name')->limit(1000)->get(['id', 'name']),
            'fuel_types' => FuelLoad::FUEL_TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(current_tenant_id_or_null(), 422, 'Elige una empresa.');
        $data = $this->validated($request);
        $load = FuelLoad::create($data + ['user_id' => $request->user()->id]);
        $this->audit->log('fuel.created', $load, ['vehicle_id' => $load->vehicle_id, 'gallons' => $load->gallons, 'total' => $load->total]);

        return back()->with('success', 'Carga de combustible registrada.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $load = FuelLoad::findOrFail($id);
        $load->update($this->validated($request, $load->id));
        $this->audit->log('fuel.updated', $load, ['vehicle_id' => $load->vehicle_id]);

        return back()->with('success', 'Carga actualizada.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $load = FuelLoad::findOrFail($id);
        $load->delete();
        $this->audit->log('fuel.deleted', $load, ['vehicle_id' => $load->vehicle_id, 'total' => $load->total]);

        return back()->with('success', 'Carga eliminada.');
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $tenant = current_tenant_id_or_null();
        $data = $request->validate([
            'vehicle_id'       => ['required', Rule::exists('vehicles', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'date'             => ['required', 'date', 'before_or_equal:today'],
            'gallons'          => ['required', 'numeric', 'min:0.001', 'max:100000'],
            'price_per_gallon' => ['required', 'numeric', 'min:0.01', 'max:1000'],
            'odometer'         => ['required', 'integer', 'min:0', 'max:99999999'],
            'fuel_type'        => ['required', Rule::in(FuelLoad::FUEL_TYPES)],
            'supplier_id'      => ['nullable', Rule::exists('suppliers', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'document_number'  => ['nullable', 'string', 'max:50'],
            'driver'           => ['nullable', 'string', 'max:150'],
        ], [], ['vehicle_id' => 'el vehículo', 'gallons' => 'los galones', 'price_per_gallon' => 'el precio por galón', 'odometer' => 'el kilometraje']);

        // El kilometraje no retrocede respecto de cargas anteriores del mismo vehículo
        $max = FuelLoad::where('vehicle_id', $data['vehicle_id'])->where('date', '<=', $data['date'])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->max('odometer');
        if ($max !== null && (int) $data['odometer'] < (int) $max) {
            throw ValidationException::withMessages(['odometer' => "El kilometraje no puede ser menor al de la carga anterior ({$max} km)."]);
        }

        return $data + ['total' => round((float) $data['gallons'] * (float) $data['price_per_gallon'], 2)];
    }
}
