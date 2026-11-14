<?php

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Permiso employees.delete: eliminar a un colaborador registrado por error, con todo lo suyo
 * (EmployeeDeletion). Por defecto solo el Super Admin; solo él puede darlo a otros roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        [$name, $group] = RolesAndPermissionsSeeder::PERMISSIONS['employees.delete'];
        $id = Permission::updateOrCreate(['slug' => 'employees.delete'], ['name' => $name, 'group' => $group])->id;
        foreach (RolesAndPermissionsSeeder::ROLES as $roleSlug => [, , $grants]) {
            if (in_array('employees.delete', RolesAndPermissionsSeeder::expand($grants), true)) {
                Role::whereNull('tenant_id')->where('slug', $roleSlug)->get()
                    ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$id]));
            }
        }
    }

    public function down(): void
    {
        Permission::where('slug', 'employees.delete')->get()->each(function (Permission $p) {
            $p->roles()->detach();
            $p->delete();
        });
    }
};
