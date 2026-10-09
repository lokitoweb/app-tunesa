<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * BranchWebController (Web)
 *
 * Gestión de sucursales, obras y definición de geocercas GPS
 * con visualización interactiva de radio permitido.
 *
 * En la vista global del Super Admin la sucursal se crea eligiendo la empresa
 * en el formulario, y al editar se usa la empresa de la propia sucursal.
 */
class BranchWebController extends Controller
{
    /** Roles que pueden quedar como responsables de una sucursal */
    private const SUPERVISOR_ROLES = ['company_admin', 'hr_manager', 'supervisor'];

    /**
     * Listado de sucursales con métricas de cobertura y empleados.
     */
    public function index(): View
    {
        $tenantId = current_tenant_id_or_null();

        $branches = Branch::forTenant($tenantId)
            ->with('supervisor')
            ->withCount(['employees' => fn($q) => $q->where('status', 'active')])
            ->orderBy('name')
            ->get();

        return view('branches.index', compact('branches'));
    }

    /**
     * Formulario de creación de sucursal con selector de mapa Leaflet.
     */
    public function create(): View
    {
        $tenantId = current_tenant_id_or_null();

        return view('branches.form', [
            'branch'      => new Branch([
                'tenant_id'             => $tenantId,
                'allowed_radius_meters' => 50,
                'is_active'             => true,
                'latitude'              => -12.046374,  // Default (e.g. Lima Centro)
                'longitude'             => -77.042793,
            ]),
            'supervisors' => $this->supervisors($tenantId),
            'companies'   => $tenantId ? null : Tenant::orderBy('company_name')->pluck('company_name', 'id'),
            'isEdit'      => false,
        ]);
    }

    /**
     * Almacena una nueva sucursal y su geocerca.
     */
    public function store(Request $request): RedirectResponse
    {
        // Vista global: la empresa llega del selector del formulario
        $tenantId = current_tenant_id_or_null()
            ?? (int) $request->validate(['tenant_id' => ['required', 'integer', 'exists:tenants,id']], [
                'tenant_id.required' => 'Elige la empresa a la que pertenece la sucursal.',
            ])['tenant_id'];

        $validated = $request->validate($this->rules($tenantId));

        $validated['tenant_id'] = $tenantId;
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['code']      = $validated['code'] ?? 'BR-' . strtoupper(substr(uniqid(), -5));

        $branch = Branch::create($validated);

        return redirect()->route('branches.index')
            ->with('success', "Sucursal '{$branch->name}' creada con geocerca de {$branch->allowed_radius_meters}m.");
    }

    /**
     * Formulario de edición con mapa interactivo centrado en la posición actual.
     */
    public function edit(int $id): View
    {
        $branch = Branch::forTenant(current_tenant_id_or_null())->with('tenant')->findOrFail($id);

        return view('branches.form', [
            'branch'      => $branch,
            'supervisors' => $this->supervisors($branch->tenant_id),
            'companies'   => null,
            'isEdit'      => true,
        ]);
    }

    /**
     * Actualiza la sucursal y su geocerca GPS.
     */
    public function update(Request $request, int $id): RedirectResponse
    {
        $branch = Branch::forTenant(current_tenant_id_or_null())->findOrFail($id);

        $validated = $request->validate($this->rules($branch->tenant_id));

        $validated['is_active'] = $request->boolean('is_active', true);
        $branch->update($validated);

        return redirect()->route('branches.index')
            ->with('success', "Sucursal '{$branch->name}' actualizada correctamente.");
    }

    /**
     * Elimina lógicamente la sucursal si no tiene registros críticos en conflicto.
     */
    public function destroy(int $id): RedirectResponse
    {
        $tenantId = current_tenant_id_or_null();
        $branch = Branch::forTenant($tenantId)->findOrFail($id);

        $branch->delete();

        return redirect()->route('branches.index')
            ->with('success', "Sucursal eliminada satisfactoriamente.");
    }

    /**
     * Responsables posibles. Sin empresa (vista global) trae los de todas,
     * y el formulario filtra la lista según la empresa elegida.
     */
    private function supervisors(?int $tenantId): Collection
    {
        return User::forTenant($tenantId)
            ->whereIn('role', self::SUPERVISOR_ROLES)
            ->where('status', 'active')
            ->orderBy('first_name')
            ->get();
    }

    /** El radio (allowed_radius_meters) es la geocerca en metros que usa la marcación */
    private function rules(int $tenantId): array
    {
        return [
            'name'                  => ['required', 'string', 'max:150'],
            'code'                  => ['nullable', 'string', 'max:50'],
            'address'               => ['required', 'string', 'max:255'],
            'city'                  => ['nullable', 'string', 'max:100'],
            'country'               => ['nullable', 'string', 'max:100'],
            'latitude'              => ['required', 'numeric', 'between:-90,90'],
            'longitude'             => ['required', 'numeric', 'between:-180,180'],
            'allowed_radius_meters' => ['required', 'integer', 'min:10', 'max:5000'],
            'supervisor_id'         => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)->whereIn('role', self::SUPERVISOR_ROLES)],
            'is_active'             => ['nullable', 'boolean'],
            'notes'                 => ['nullable', 'string', 'max:500'],
        ];
    }
}
