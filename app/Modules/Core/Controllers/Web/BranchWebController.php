<?php

namespace App\Modules\Core\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\CompanyConfiguration;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Services\TenantContext;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
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
                // Radio por defecto de "Marca y app › Reglas operativas"
                'allowed_radius_meters' => TenantSetting::forTenant(app(TenantContext::class)->getOrNull())->default_geofence_radius,
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
        app(AuditLogger::class)->logCreated('branch.created', $branch, ['name' => $branch->name]);

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
        app(AuditLogger::class)->updateWithAudit('branch.updated', $branch, $validated, ['name' => $branch->name]);

        return redirect()->route('branches.index')
            ->with('success', "Sucursal '{$branch->name}' actualizada correctamente.");
    }

    /**
     * Elimina lógicamente la sucursal; no si aún tiene colaboradores activos asignados.
     */
    public function destroy(int $id): RedirectResponse
    {
        $tenantId = current_tenant_id_or_null();
        $branch = Branch::forTenant($tenantId)->findOrFail($id);

        $active = $branch->employees()->where('users.status', 'active')->count();
        if ($active > 0) {
            return redirect()->route('branches.index')->with('error',
                "No se puede eliminar {$branch->name}: tiene {$active} colaborador(es) activo(s) asignado(s). Reasígnalos a otra sucursal primero.");
        }

        $branch->delete();
        app(AuditLogger::class)->log('branch.deleted', $branch, ['name' => $branch->name]);

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
            'geofence_tolerance_meters' => ['nullable', 'integer', 'min:0', 'max:100'],
            'max_gps_accuracy_meters'   => ['nullable', 'integer', 'min:5', 'max:500'],
            'geofence_policy'           => ['nullable', Rule::in(array_keys(CompanyConfiguration::GEOFENCE_POLICIES))],
            'zone_id'               => ['sometimes', 'nullable', 'integer', Rule::exists('zones', 'id')->where('tenant_id', $tenantId)],
            'branch_type'           => ['sometimes', Rule::in(array_keys(Branch::TYPES))],
            'supervisor_id'         => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)->whereIn('role', self::SUPERVISOR_ROLES)],
            'is_active'             => ['nullable', 'boolean'],
            'notes'                 => ['nullable', 'string', 'max:500'],
        ];
    }
}
