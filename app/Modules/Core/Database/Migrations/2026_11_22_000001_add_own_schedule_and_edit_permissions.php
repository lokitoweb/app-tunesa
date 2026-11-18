<?php

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Dos permisos sobre lo propio, administrables en Roles y permisos:
 *  - shifts.program_own: «Programar su propio horario». Por defecto todos los que programan horarios
 *    (Supervisor, RR. HH., Administrador) y los roles propios basados en ellos.
 *  - attendance.edit_own: «Corregir sus propias marcaciones». Por defecto RR. HH. y Administrador.
 * Lo que cada empresa personalizó se respeta: sus ajustes son diferencias sobre el estándar.
 */
return new class extends Migration
{
    private const SLUGS = ['shifts.program_own', 'attendance.edit_own'];

    public function up(): void
    {
        foreach (self::SLUGS as $slug) {
            [$name, $group] = RolesAndPermissionsSeeder::PERMISSIONS[$slug];
            $id = Permission::updateOrCreate(['slug' => $slug], ['name' => $name, 'group' => $group])->id;

            foreach (RolesAndPermissionsSeeder::ROLES as $roleSlug => [, , $grants]) {
                if (in_array($slug, RolesAndPermissionsSeeder::expand($grants), true)) {
                    Role::whereNull('tenant_id')->where('slug', $roleSlug)->get()
                        ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$id]));
                }
            }
        }
        // Los roles propios de cada empresa no guardan permisos: parten de su rol base (RolePermissions::standard)
    }

    public function down(): void
    {
        Permission::whereIn('slug', self::SLUGS)->get()->each(function (Permission $p) {
            $p->roles()->detach();
            $p->delete();
        });
    }
};
