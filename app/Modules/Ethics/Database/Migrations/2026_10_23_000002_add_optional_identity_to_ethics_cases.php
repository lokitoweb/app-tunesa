<?php

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canal ético: identidad opcional. Quien denuncia desde la app puede elegir
 * identificarse; sus datos van cifrados y solo los ve quien tiene
 * ethics.view_identity (RRHH y administradores, no jefaturas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ethics_cases', function (Blueprint $table) {
            $table->boolean('identified')->default(false);
            $table->text('reporter_identity')->nullable();
        });

        [$name, $group] = RolesAndPermissionsSeeder::PERMISSIONS['ethics.view_identity'];
        $permission = Permission::updateOrCreate(['slug' => 'ethics.view_identity'], ['name' => $name, 'group' => $group]);

        foreach (RolesAndPermissionsSeeder::ROLES as $roleSlug => [, , $grants]) {
            if (in_array('ethics.view_identity', RolesAndPermissionsSeeder::expand($grants), true)) {
                Role::whereNull('tenant_id')->where('slug', $roleSlug)->get()
                    ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]));
            }
        }
    }

    public function down(): void
    {
        Permission::where('slug', 'ethics.view_identity')->get()->each(function (Permission $p) {
            $p->roles()->detach();
            $p->delete();
        });

        Schema::table('ethics_cases', function (Blueprint $table) {
            $table->dropColumn(['identified', 'reporter_identity']);
        });
    }
};
