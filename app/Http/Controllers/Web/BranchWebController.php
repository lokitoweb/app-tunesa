<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * BranchWebController (Web)
 *
 * Gestión de sucursales, obras y definición de geocercas GPS
 * con visualización interactiva de radio permitido.
 */
class BranchWebController extends Controller
{
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

        $supervisors = User::forTenant($tenantId)
            ->whereIn('role', ['company_admin', 'hr_manager', 'supervisor'])
            ->where('status', 'active')
            ->get();

        return view('branches.form', [
            'branch'      => new Branch([
                'allowed_radius_meters' => 50,
                'is_active'             => true,
                'latitude'              => -12.046374,  // Default (e.g. Lima Centro)
                'longitude'             => -77.042793,
            ]),
            'supervisors' => $supervisors,
            'isEdit'      => false,
        ]);
    }

    /**
     * Almacena una nueva sucursal y su geocerca.
     */
    public function store(Request $request): RedirectResponse
    {
        $tenantId = current_tenant_id_or_null();

        $validated = $request->validate([
            'name'                  => ['required', 'string', 'max:150'],
            'code'                  => ['nullable', 'string', 'max:50'],
            'address'               => ['required', 'string', 'max:255'],
            'city'                  => ['nullable', 'string', 'max:100'],
            'country'               => ['nullable', 'string', 'max:100'],
            'latitude'              => ['required', 'numeric', 'between:-90,90'],
            'longitude'             => ['required', 'numeric', 'between:-180,180'],
            'allowed_radius_meters' => ['required', 'integer', 'min:10', 'max:5000'],
            'supervisor_id'         => ['nullable', 'exists:users,id'],
            'is_active'             => ['nullable', 'boolean'],
            'notes'                 => ['nullable', 'string', 'max:500'],
        ]);

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
        $tenantId = current_tenant_id_or_null();
        $branch = Branch::forTenant($tenantId)->findOrFail($id);

        $supervisors = User::forTenant($tenantId)
            ->whereIn('role', ['company_admin', 'hr_manager', 'supervisor'])
            ->where('status', 'active')
            ->get();

        return view('branches.form', [
            'branch'      => $branch,
            'supervisors' => $supervisors,
            'isEdit'      => true,
        ]);
    }

    /**
     * Actualiza la sucursal y su geocerca GPS.
     */
    public function update(Request $request, int $id): RedirectResponse
    {
        $tenantId = current_tenant_id_or_null();
        $branch = Branch::forTenant($tenantId)->findOrFail($id);

        $validated = $request->validate([
            'name'                  => ['required', 'string', 'max:150'],
            'code'                  => ['nullable', 'string', 'max:50'],
            'address'               => ['required', 'string', 'max:255'],
            'city'                  => ['nullable', 'string', 'max:100'],
            'country'               => ['nullable', 'string', 'max:100'],
            'latitude'              => ['required', 'numeric', 'between:-90,90'],
            'longitude'             => ['required', 'numeric', 'between:-180,180'],
            'allowed_radius_meters' => ['required', 'integer', 'min:10', 'max:5000'],
            'supervisor_id'         => ['nullable', 'exists:users,id'],
            'is_active'             => ['nullable', 'boolean'],
            'notes'                 => ['nullable', 'string', 'max:500'],
        ]);

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
}
