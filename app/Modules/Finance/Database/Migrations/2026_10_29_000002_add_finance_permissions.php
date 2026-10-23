<?php

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Permisos de compras y tesorería y el rol Logística para las bases que ya
 * existen. Solo agrega (syncWithoutDetaching): ningún rol pierde permisos,
 * el Super Admin incluido.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = [];
        foreach (RolesAndPermissionsSeeder::FINANCE as $slug) {
            [$name, $group] = RolesAndPermissionsSeeder::PERMISSIONS[$slug];
            $ids[$slug] = Permission::updateOrCreate(['slug' => $slug], ['name' => $name, 'group' => $group])->id;
        }

        [$name, $description] = RolesAndPermissionsSeeder::ROLES[Role::LOGISTICS];
        Role::updateOrCreate(['tenant_id' => null, 'slug' => Role::LOGISTICS], ['name' => $name, 'description' => $description, 'is_system' => true]);

        foreach (RolesAndPermissionsSeeder::ROLES as $roleSlug => [, , $grants]) {
            $expanded = RolesAndPermissionsSeeder::expand($grants);
            $grant = array_values(array_intersect_key($ids, array_flip($expanded)));
            $role = Role::whereNull('tenant_id')->where('slug', $roleSlug)->first();
            $role?->permissions()->syncWithoutDetaching($grant);
            if ($roleSlug === Role::LOGISTICS) {
                $role->permissions()->syncWithoutDetaching(Permission::whereIn('slug', $expanded)->pluck('id'));
            }
        }
    }

    public function down(): void
    {
        Role::whereNull('tenant_id')->where('slug', Role::LOGISTICS)->get()->each(function (Role $role) {
            $role->permissions()->detach();
            $role->delete();
        });
        Permission::whereIn('slug', RolesAndPermissionsSeeder::FINANCE)->get()->each(function (Permission $p) {
            $p->roles()->detach();
            $p->delete();
        });
    }
};
