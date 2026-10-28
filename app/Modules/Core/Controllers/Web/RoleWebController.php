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
        $standard = $this->roles->standard();

        return Inertia::render('Core/Pages/Roles/Index', [
            'roles'     => collect(RolePermissions::EDITABLE)->map(fn ($role) => [
                'slug' => $role, 'name' => RolesAndPermissionsSeeder::ROLES[$role][0], 'description' => RolesAndPermissionsSeeder::ROLES[$role][1],
            ])->values(),
            'groups'    => collect($this->roles->grantable($tenant))->map(fn ($permissions, $group) => [
                'key' => $group, 'label' => RolePermissions::GROUP_LABELS[$group] ?? ucfirst($group), 'permissions' => $permissions,
            ])->values(),
            'effective' => $this->roles->effective($tenant),
            'standard'  => $standard,
            'company'   => $tenant->company_name,
        ]);
    }

    public function update(Request $request, string $role): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['string', 'max:100']]);

        $diff = $this->roles->save($tenant, $role, $data['permissions'], $request->user()->id);
        if ($diff['added'] || $diff['removed']) {
            $this->audit->log('roles.updated', null, ['role' => $role, 'added' => $diff['added'], 'removed' => $diff['removed']]);
        }

        return back()->with('success', 'Permisos de ' . RolesAndPermissionsSeeder::ROLES[$role][0] . ' guardados. Rigen desde la próxima pantalla que abra cada persona.');
    }

    public function reset(Request $request, string $role): RedirectResponse
    {
        $tenant = $this->tenant();
        $this->roles->reset($tenant, $role);
        $this->audit->log('roles.reset', null, ['role' => $role]);

        return back()->with('success', RolesAndPermissionsSeeder::ROLES[$role][0] . ' volvió a los permisos estándar.');
    }

    private function tenant(): Tenant
    {
        // Se personaliza dentro de una empresa: en la vista global el Super Admin elige una antes
        $tenant = Tenant::find(current_tenant_id_or_null());
        abort_unless($tenant, 422, 'Elige una empresa para personalizar sus roles.');

        return $tenant;
    }
}
