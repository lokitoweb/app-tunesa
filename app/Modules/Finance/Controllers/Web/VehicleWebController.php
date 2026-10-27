<?php

namespace App\Modules\Finance\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Finance\Models\FuelLoad;
use App\Modules\Finance\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Maestro de vehículos (Tbus): placa única por empresa, tipo y sede base */
class VehicleWebController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search'));

        return Inertia::render('Finance/Pages/Vehicles/Index', [
            'vehicles' => Vehicle::with('branch:id,name')
                ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('plate', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")->orWhere('model', 'like', "%{$search}%")))
                ->orderByDesc('is_active')->orderBy('plate')->paginate(50)->withQueryString()
                ->through(fn (Vehicle $v) => $v->only(['id', 'plate', 'brand', 'model', 'type', 'branch_id', 'is_active']) + ['branch' => $v->branch?->name]),
            'filters'  => ['search' => $search],
            'types'    => Vehicle::TYPES,
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(current_tenant_id_or_null(), 422, 'Elige una empresa.');
        $data = $this->validated($request);

        // La placa identifica al vehículo (índice único por empresa, también entre los eliminados):
        // si se había eliminado, se reactiva el mismo con los datos nuevos, sin duplicar
        if ($deleted = Vehicle::onlyTrashed()->where('plate', $data['plate'])->first()) {
            $deleted->restore();
            $deleted->update($data);
            $this->audit->log('vehicle.restored', $deleted, ['plate' => $deleted->plate]);

            return back()->with('success', "La placa {$deleted->plate} era de un vehículo eliminado: se reactivó con los datos nuevos.");
        }

        $vehicle = Vehicle::create($data);
        $this->audit->log('vehicle.created', $vehicle, ['plate' => $vehicle->plate]);

        return back()->with('success', "Vehículo {$vehicle->plate} registrado.");
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $vehicle = Vehicle::findOrFail($id);
        $vehicle->update($this->validated($request, $vehicle->id));
        $this->audit->log('vehicle.updated', $vehicle, ['plate' => $vehicle->plate]);

        return back()->with('success', 'Vehículo actualizado.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $vehicle = Vehicle::findOrFail($id);
        // Con historial (combustible o requerimientos) se desactiva: la traza no se pierde
        abort_if(FuelLoad::where('vehicle_id', $vehicle->id)->exists(), 422, 'El vehículo tiene cargas de combustible: desactívalo en lugar de eliminarlo.');
        $vehicle->delete();
        $this->audit->log('vehicle.deleted', $vehicle, ['plate' => $vehicle->plate]);

        return back()->with('success', 'Vehículo eliminado.');
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $request->merge(['plate' => Vehicle::normalizePlate($request->input('plate'))]);

        return $request->validate([
            // Al registrar, la placa de un vehículo eliminado se reactiva (store); al editar no puede
            // tomar la de otro, ni siquiera eliminado (el índice único de la base no lo permite)
            'plate'     => ['required', 'string', 'max:15', 'regex:/^[A-Z0-9-]+$/', $ignoreId
                ? Rule::unique('vehicles')->where('tenant_id', current_tenant_id_or_null())->ignore($ignoreId)
                : Rule::unique('vehicles')->where('tenant_id', current_tenant_id_or_null())->whereNull('deleted_at')],
            'brand'     => ['nullable', 'string', 'max:60'],
            'model'     => ['nullable', 'string', 'max:60'],
            'type'      => ['required', Rule::in(Vehicle::TYPES)],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', current_tenant_id_or_null())->whereNull('deleted_at')],
            'is_active' => ['boolean'],
        ], ['plate.unique' => 'Esa placa ya está registrada.', 'plate.regex' => 'La placa solo lleva letras, números y guion.'],
            ['plate' => 'la placa', 'type' => 'el tipo', 'branch_id' => 'la sede']);
    }
}
