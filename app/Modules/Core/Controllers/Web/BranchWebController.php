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
            ->with(['supervisors:users.id,users.first_name,users.last_name', 'zone', 'tenant'])
            ->withCount(['employees' => fn($q) => $q->where('status', 'active')])
            ->orderBy('name')
            ->get();

        $supervisors = $this->supervisors($tenantId);
        $zones = $tenantId
            ? \App\Modules\Core\Models\Zone::where('tenant_id', $tenantId)->orderBy('name')->get()
            : \App\Modules\Core\Models\Zone::orderBy('name')->get();
        $companies = $tenantId ? null : Tenant::orderBy('company_name')->pluck('company_name', 'id');
        $defaultRadius = TenantSetting::forTenant(app(TenantContext::class)->getOrNull())->default_geofence_radius ?? 100;
        $branchTypes = Branch::TYPES;

        return view('branches.index', compact(
            'branches',
            'supervisors',
            'zones',
            'companies',
            'defaultRadius',
            'branchTypes'
        ));
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
        $supervisorIds = $this->supervisorIds($request, $validated);

        $branch = \Illuminate\Support\Facades\DB::transaction(function () use ($validated, $supervisorIds) {
            $branch = Branch::create($validated);
            $branch->syncSupervisors($supervisorIds ?? []);

            return $branch;
        });
        app(AuditLogger::class)->logCreated('branch.created', $branch, ['name' => $branch->name, 'supervisors' => $this->supervisorNames($branch)]);

        $msg = "Sucursal '{$branch->name}' creada con geocerca de {$branch->allowed_radius_meters}m.";
        if ($request->header('X-Inertia') || $request->wantsJson()) {
            return back()->with('success', $msg);
        }

        return redirect()->route('branches.index')->with('success', $msg);
    }

    /**
     * Formulario de edición con mapa interactivo centrado en la posición actual.
     */
    public function edit(int $id): View
    {
        $branch = Branch::forTenant(current_tenant_id_or_null())->with(['tenant', 'supervisors:users.id'])->findOrFail($id);

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
        $supervisorIds = $this->supervisorIds($request, $validated);
        $before = $this->supervisorNames($branch);

        \Illuminate\Support\Facades\DB::transaction(function () use ($branch, $validated, $supervisorIds) {
            app(AuditLogger::class)->updateWithAudit('branch.updated', $branch, $validated, ['name' => $branch->name]);
            if ($supervisorIds !== null) {
                $branch->syncSupervisors($supervisorIds);
            }
        });
        if (($after = $this->supervisorNames($branch)) !== $before) {
            app(AuditLogger::class)->log('branch.supervisors_changed', $branch, ['name' => $branch->name, 'before' => $before ?: 'Sin supervisor', 'after' => $after ?: 'Sin supervisor']);
        }

        $msg = "Sucursal '{$branch->name}' actualizada correctamente.";
        if ($request->header('X-Inertia') || $request->wantsJson()) {
            return back()->with('success', $msg);
        }

        return redirect()->route('branches.index')->with('success', $msg);
    }

    /**
     * Elimina lógicamente la sucursal; no si aún tiene colaboradores activos asignados.
     */
    public function destroy(Request $request, int $id): RedirectResponse
    {
        $tenantId = current_tenant_id_or_null();
        $branch = Branch::forTenant($tenantId)->findOrFail($id);

        $active = $branch->employees()->where('users.status', 'active')->count();
        if ($active > 0) {
            $msg = "No se puede eliminar {$branch->name}: tiene {$active} colaborador(es) activo(s) asignado(s). Reasígnalos a otra sucursal primero.";
            if ($request->header('X-Inertia') || $request->wantsJson()) {
                return back()->with('error', $msg);
            }
            return redirect()->route('branches.index')->with('error', $msg);
        }

        $branch->delete();
        app(AuditLogger::class)->log('branch.deleted', $branch, ['name' => $branch->name]);

        $msg = "Sucursal eliminada satisfactoriamente.";
        if ($request->header('X-Inertia') || $request->wantsJson()) {
            return back()->with('success', $msg);
        }

        return redirect()->route('branches.index')->with('success', $msg);
    }

    /**
     * Supervisores elegidos (supervisor_ids[]); un formulario o integración antigua que aún manda
     * supervisor_id (uno solo) sigue funcionando. Se quitan de $validated: se guardan en la lista.
     */
    private function supervisorIds(Request $request, array &$validated): ?array
    {
        // El formulario manda supervisors_sent=1 (las casillas desmarcadas no viajan): sin él ni
        // supervisor_id, la lista no se toca (un guardado parcial no borra a los supervisores)
        $ids = match (true) {
            $request->boolean('supervisors_sent') || $request->has('supervisor_ids') => (array) ($validated['supervisor_ids'] ?? []),
            $request->exists('supervisor_id') => array_filter([$validated['supervisor_id'] ?? null]),
            default => null,
        };
        unset($validated['supervisor_ids'], $validated['supervisor_id'], $validated['supervisors_sent']);

        return $ids === null ? null : array_values(array_unique(array_map('intval', $ids)));
    }

    /** "Ana Díaz, Juan Pérez": para la bitácora */
    private function supervisorNames(Branch $branch): string
    {
        return $branch->supervisors()->get(['users.first_name', 'users.last_name'])->map(fn ($u) => $u->full_name)->join(', ');
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
            // Serie de la sede en los correlativos RQ/OC/OS (001, SED-01): única en la empresa
            'document_series'       => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('branches', 'document_series')->where('tenant_id', $tenantId)->whereNull('deleted_at')->ignore(request()->route('id'))],
            'address'               => ['required', 'string', 'max:255'],
            'city'                  => ['nullable', 'string', 'max:100'],
            'province'              => ['nullable', 'string', 'max:100'],
            'district'              => ['nullable', 'string', 'max:100'],
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
            // Varios supervisores (mañana, tarde o a la vez): activos, de esta empresa y con rol que supervisa
            'supervisors_sent'      => ['nullable', 'boolean'],
            'supervisor_ids'        => ['nullable', 'array', 'max:50'],
            'supervisor_ids.*'      => ['integer', 'distinct', Rule::exists('users', 'id')->where('tenant_id', $tenantId)->where('status', 'active')->whereNull('deleted_at')->whereIn('role', self::SUPERVISOR_ROLES)],
            'is_active'             => ['nullable', 'boolean'],
            'notes'                 => ['nullable', 'string', 'max:500'],
        ];
    }
}
