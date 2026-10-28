<?php

namespace App\Modules\Core\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\RolePermissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración › Roles y permisos: cada empresa ajusta qué puede hacer cada rol, sobre el estándar.
 * Solo módulos activos de la empresa; el Administrador de empresa y el Super Admin no se editan.
 */
class RoleWebController extends Controller
{
    public function __construct(private readonly RolePermissions $roles, private readonly AuditLogger $audit) {}

    public function index(): Response|RedirectResponse
    {
        $tenant = $this->tenant();
        $standard = $this->roles->standard($tenant);
        $system = collect(RolePermissions::EDITABLE)->map(fn ($role) => [
            'slug' => $role, 'name' => RolesAndPermissionsSeeder::ROLES[$role][0], 'description' => RolesAndPermissionsSeeder::ROLES[$role][1],
            'custom' => false,
        ]);
        $custom = $this->roles->customRoles($tenant)->map(fn ($r) => [
            'slug' => $r->slug, 'name' => $r->name, 'custom' => true, 'users' => (int) $r->users_count,
            'description' => $r->description ?: 'Basado en ' . RolesAndPermissionsSeeder::ROLES[$r->base_role][0] . '.',
            'base' => RolesAndPermissionsSeeder::ROLES[$r->base_role][0],
        ]);

        return Inertia::render('Core/Pages/Roles/Index', [
            'roles'     => $system->concat($custom)->values(),
            'bases'     => $system->map(fn ($r) => ['slug' => $r['slug'], 'name' => $r['name']])->values(),
            'groups'    => collect($this->roles->grantable($tenant))->map(fn ($permissions, $group) => [
                'key' => $group, 'label' => RolePermissions::GROUP_LABELS[$group] ?? ucfirst($group), 'permissions' => $permissions,
            ])->values(),
            'effective' => $this->roles->effective($tenant),
            'standard'  => $standard,
            'company'   => $tenant->company_name,
        ]);
    }

    /** Rol propio de la empresa, basado en uno del sistema */
    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:60'],
            'base_role'   => ['required', 'in:' . implode(',', RolePermissions::EDITABLE)],
            'description' => ['nullable', 'string', 'max:200'],
        ], [], ['name' => 'el nombre', 'base_role' => 'el rol base']);

        $role = $this->roles->createCustom($tenant, $data['name'], $data['base_role'], $data['description'] ?? null);
        $this->audit->log('roles.created', null, ['role' => $role->slug, 'name' => $role->name, 'base' => $role->base_role]);

        return back()->with('success', "Rol «{$role->name}» creado con los permisos de su rol base: ajústalos y asígnalo en Colaboradores.");
    }

    public function destroy(string $role): RedirectResponse
    {
        $this->roles->deleteCustom($this->tenant(), $role);
        $this->audit->log('roles.deleted', null, ['role' => $role]);

        return back()->with('success', 'Rol eliminado.');
    }

    public function update(Request $request, string $role): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['string', 'max:100']]);

        $diff = $this->roles->save($tenant, $role, $data['permissions'], $request->user()->id);
        if ($diff['added'] || $diff['removed']) {
            $this->audit->log('roles.updated', null, ['role' => $role, 'added' => $diff['added'], 'removed' => $diff['removed']]);
        }

        return back()->with('success', 'Permisos guardados. Rigen desde la próxima pantalla que abra cada persona.');
    }

    public function reset(Request $request, string $role): RedirectResponse
    {
        $tenant = $this->tenant();
        $this->roles->reset($tenant, $role);
        $this->audit->log('roles.reset', null, ['role' => $role]);

        return back()->with('success', 'El rol volvió a los permisos de su rol base.');
    }

    private function tenant(): Tenant
    {
        // Se personaliza dentro de una empresa: en la vista global el Super Admin elige una antes
        $tenant = Tenant::find(current_tenant_id_or_null());
        abort_unless($tenant, 422, 'Elige una empresa para personalizar sus roles.');

        return $tenant;
    }
}
