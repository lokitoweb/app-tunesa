<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Models\RolePermissionOverride;
use App\Modules\Core\Models\Tenant;
use App\Modules\ModuleRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Roles y permisos personalizables por empresa. Se guarda solo la diferencia con el rol estándar
 * (RolePermissionOverride), así lo nuevo del sistema llega solo a todas las empresas.
 *
 * Límites: el Super Admin y el Administrador de empresa no se editan (tienen todo); los permisos de
 * plataforma y el de editar roles no se otorgan a otros roles (quien los tuviera podría darse
 * cualquier cosa).
 */
class RolePermissions
{
    /** Roles que cada empresa puede ajustar */
    public const EDITABLE = [Role::HR_MANAGER, Role::MANAGER, Role::ACCOUNTANT, Role::SUPERVISOR, Role::LOGISTICS, Role::EMPLOYEE];

    /** Nunca se otorgan a un rol de empresa */
    public const LOCKED = ['tenants.manage', 'roles.manage'];

    /** Grupo de permisos → módulo que lo activa (los grupos sin módulo son del núcleo) */
    private const GROUP_MODULE = [
        'training' => 'Training', 'benefits' => 'Benefits', 'birthdays' => 'Birthdays', 'company_docs' => 'CompanyDocs',
        'announcements' => 'Announcements', 'onboarding' => 'Onboarding', 'ethics' => 'Ethics', 'finance' => 'Finance',
        'payslips' => 'Payroll', 'attendance' => 'Attendance', 'shifts' => 'HR', 'leave' => 'HR', 'profiles' => 'HR',
    ];

    /** Nombre de cada grupo en la pantalla */
    public const GROUP_LABELS = [
        'tenants' => 'Empresa', 'roles' => 'Roles', 'users' => 'Colaboradores', 'branches' => 'Sucursales',
        'shifts' => 'Turnos y horarios', 'attendance' => 'Asistencia', 'dashboard' => 'Dashboard', 'reports' => 'Reportes',
        'leave' => 'Solicitudes', 'payslips' => 'Boletas y planilla', 'profiles' => 'Legajo y ficha laboral',
        'training' => 'Capacitaciones', 'benefits' => 'Beneficios', 'birthdays' => 'Cumpleaños', 'company_docs' => 'Documentos de la empresa',
        'announcements' => 'Comunicados', 'onboarding' => 'Incorporación', 'ethics' => 'Canal ético', 'finance' => 'Compras y tesorería',
    ];

    /**
     * Permisos de partida de cada rol: los del sistema y, con $tenant, también los roles propios de esa
     * empresa (parten de los de su rol base).
     *
     * @return array<string, list<string>> rol => permisos estándar
     */
    public function standard(?Tenant $tenant = null): array
    {
        $system = fn (string $role) => RolesAndPermissionsSeeder::expand(RolesAndPermissionsSeeder::ROLES[$role][2]);
        $roles = collect(self::EDITABLE)->mapWithKeys(fn ($role) => [$role => $system($role)]);
        if ($tenant) {
            $roles = $roles->merge($this->customRoles($tenant)->mapWithKeys(fn (Role $r) => [$r->slug => $system($r->base_role)]));
        }

        return $roles->all();
    }

    /** Roles propios de la empresa ("Jefe de flota"…), con cuántas personas lo tienen */
    public function customRoles(Tenant $tenant): \Illuminate\Support\Collection
    {
        return Role::query()->where('tenant_id', $tenant->id)->whereIn('base_role', self::EDITABLE)->orderBy('name')
            ->withCount(['customUsers as users_count' => fn ($q) => $q->withoutGlobalScopes()])
            ->get(['id', 'slug', 'name', 'description', 'base_role']);
    }

    /** Crea un rol propio basado en un rol del sistema; empieza con los permisos de su base */
    public function createCustom(Tenant $tenant, string $name, string $base, ?string $description): Role
    {
        abort_unless(in_array($base, self::EDITABLE, true), 422, 'Elige en qué rol se basa.');
        $slug = 'c-' . (\Illuminate\Support\Str::slug($name) ?: 'rol');
        $taken = Role::query()->where('tenant_id', $tenant->id)->where('slug', 'like', "{$slug}%")->pluck('slug')->all();
        for ($n = 2, $candidate = $slug; in_array($candidate, $taken, true); $n++) {
            $candidate = "{$slug}-{$n}";
        }
        if (Role::query()->where('tenant_id', $tenant->id)->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['name' => 'Ya existe un rol con ese nombre.']);
        }

        return Role::create(['tenant_id' => $tenant->id, 'slug' => $candidate, 'base_role' => $base, 'name' => trim($name),
            'description' => $description, 'is_system' => false]);
    }

    /** Solo si nadie lo tiene: así nadie se queda sin saber qué permisos le tocan */
    public function deleteCustom(Tenant $tenant, string $slug): void
    {
        $role = Role::query()->where('tenant_id', $tenant->id)->where('slug', $slug)->whereNotNull('base_role')->firstOrFail();
        $users = \App\Modules\Core\Models\User::withoutGlobalScopes()->where('custom_role_id', $role->id)->count();
        abort_if($users > 0, 422, "El rol lo tienen {$users} persona(s): cámbiales el rol antes de eliminarlo.");
        DB::transaction(function () use ($tenant, $role) {
            RolePermissionOverride::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('role', $role->slug)->delete();
            $role->delete();
        });
    }

    /** @return array<string, list<string>> rol => permisos efectivos en la empresa */
    public function effective(Tenant $tenant): array
    {
        $overrides = RolePermissionOverride::withoutGlobalScopes()->where('tenant_id', $tenant->id)->with('permission:id,slug')->get()->groupBy('role');

        return collect($this->standard($tenant))->map(function (array $slugs, string $role) use ($overrides) {
            $rows = $overrides->get($role, collect());

            return array_values(array_unique(array_merge(
                array_diff($slugs, $rows->where('granted', false)->pluck('permission.slug')->all()),
                $rows->where('granted', true)->pluck('permission.slug')->all(),
            )));
        })->all();
    }

    /**
     * Permisos que se pueden marcar, por grupo, solo de los módulos activos de la empresa.
     *
     * @return array<string, list<array{slug: string, name: string}>>
     */
    public function grantable(Tenant $tenant): array
    {
        return Permission::whereNotIn('slug', self::LOCKED)->orderBy('id')->get(['slug', 'name', 'group'])
            ->filter(fn ($p) => ! isset(self::GROUP_MODULE[$p->group]) || ModuleRegistry::enabledFor($tenant, self::GROUP_MODULE[$p->group]))
            ->groupBy('group')
            ->map(fn ($g) => $g->map(fn ($p) => ['slug' => $p->slug, 'name' => $p->name])->values()->all())
            ->all();
    }

    /**
     * Guarda los permisos de un rol en la empresa como diferencia con el estándar. Lo que no es
     * otorgable (bloqueado o de un módulo apagado) conserva su estado actual.
     *
     * @param  list<string>  $wanted
     * @return array{added: list<string>, removed: list<string>}
     */
    public function save(Tenant $tenant, string $role, array $wanted, ?int $by): array
    {
        $all = $this->standard($tenant);
        abort_unless(array_key_exists($role, $all), 422, 'Ese rol no se puede personalizar.');
        $grantable = collect($this->grantable($tenant))->flatten(1)->pluck('slug')->all();
        $standard = $all[$role];
        $before = $this->effective($tenant)[$role];
        // Fuera de lo editable se respeta lo que ya había
        $wanted = array_values(array_unique(array_merge(array_intersect($wanted, $grantable), array_diff($before, $grantable))));

        $ids = Permission::whereIn('slug', array_merge($standard, $wanted))->pluck('id', 'slug');
        DB::transaction(function () use ($tenant, $role, $standard, $wanted, $ids, $by) {
            RolePermissionOverride::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('role', $role)->delete();
            $rows = [];
            foreach (array_diff($wanted, $standard) as $slug) {
                $rows[] = ['permission_id' => $ids[$slug], 'granted' => true];
            }
            foreach (array_diff($standard, $wanted) as $slug) {
                $rows[] = ['permission_id' => $ids[$slug], 'granted' => false];
            }
            foreach ($rows as $row) {
                RolePermissionOverride::create($row + ['tenant_id' => $tenant->id, 'role' => $role, 'updated_by' => $by]);
            }
        });

        return ['added' => array_values(array_diff($wanted, $before)), 'removed' => array_values(array_diff($before, $wanted))];
    }

    public function reset(Tenant $tenant, string $role): void
    {
        abort_unless(array_key_exists($role, $this->standard($tenant)), 422, 'Ese rol no se puede personalizar.');
        RolePermissionOverride::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('role', $role)->delete();
    }
}
