<?php

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Permisos de beneficios, cumpleaños y documentos de la empresa para las
 * bases que ya existen: los crea y los agrega a los roles del sistema según
 * RolesAndPermissionsSeeder, sin tocar los demás permisos de cada rol.
 */
return new class extends Migration
{
    private const PREFIXES = ['benefits.', 'birthdays.', 'company_docs.'];

    public function up(): void
    {
        $slugs = array_filter(array_keys(RolesAndPermissionsSeeder::PERMISSIONS), fn ($s) => str_starts_with($s, 'benefits.')
            || str_starts_with($s, 'birthdays.') || str_starts_with($s, 'company_docs.'));

        $ids = [];
        foreach ($slugs as $slug) {
            [$name, $group] = RolesAndPermissionsSeeder::PERMISSIONS[$slug];
            $ids[$slug] = Permission::updateOrCreate(['slug' => $slug], ['name' => $name, 'group' => $group])->id;
        }

        foreach (RolesAndPermissionsSeeder::ROLES as $roleSlug => [, , $grants]) {
            $grant = array_values(array_intersect_key($ids, array_flip(RolesAndPermissionsSeeder::expand($grants))));
            if ($grant) {
                Role::whereNull('tenant_id')->where('slug', $roleSlug)->get()
                    ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($grant));
            }
        }
    }

    public function down(): void
    {
        foreach (self::PREFIXES as $prefix) {
            Permission::where('slug', 'like', $prefix . '%')->get()->each(function (Permission $p) {
                $p->roles()->detach();
                $p->delete();
            });
        }
    }
};
