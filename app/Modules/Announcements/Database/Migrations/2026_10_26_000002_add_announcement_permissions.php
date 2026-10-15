<?php

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/** Permisos de comunicados para las bases que ya existen (mismo criterio que el seeder) */
return new class extends Migration
{
    public function up(): void
    {
        $ids = [];
        foreach (RolesAndPermissionsSeeder::PERMISSIONS as $slug => [$name, $group]) {
            if (str_starts_with($slug, 'announcements.')) {
                $ids[$slug] = Permission::updateOrCreate(['slug' => $slug], ['name' => $name, 'group' => $group])->id;
            }
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
        Permission::where('slug', 'like', 'announcements.%')->get()->each(function (Permission $p) {
            $p->roles()->detach();
            $p->delete();
        });
    }
};
