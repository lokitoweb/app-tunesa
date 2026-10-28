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

    /** @return array<string, list<string>> rol => permisos estándar */
    public function standard(): array
    {
        return collect(self::EDITABLE)->mapWithKeys(fn ($role) => [
            $role => RolesAndPermissionsSeeder::expand(RolesAndPermissionsSeeder::ROLES[$role][2]),
        ])->all();
    }

    /** @return array<string, list<string>> rol => permisos efectivos en la empresa */
    public function effective(Tenant $tenant): array
    {
        $overrides = RolePermissionOverride::withoutGlobalScopes()->where('tenant_id', $tenant->id)->with('permission:id,slug')->get()->groupBy('role');

        return collect($this->standard())->map(function (array $slugs, string $role) use ($overrides) {
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
        abort_unless(in_array($role, self::EDITABLE, true), 422, 'Ese rol no se puede personalizar.');
        $grantable = collect($this->grantable($tenant))->flatten(1)->pluck('slug')->all();
        $standard = $this->standard()[$role];
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
        abort_unless(in_array($role, self::EDITABLE, true), 422, 'Ese rol no se puede personalizar.');
        RolePermissionOverride::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('role', $role)->delete();
    }
}
