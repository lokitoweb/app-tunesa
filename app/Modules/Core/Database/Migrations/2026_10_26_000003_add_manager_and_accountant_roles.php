<?php

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles Gerencia (manager) y Contabilidad (accountant). users.role pasa de
 * enum a texto para no tener que alterar la columna con cada rol nuevo; la
 * validación de roles está en los formularios y en Role::ALL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 30)->default('employee')->change();
        });

        foreach ([Role::MANAGER, Role::ACCOUNTANT] as $slug) {
            [$name, $description, $grants] = RolesAndPermissionsSeeder::ROLES[$slug];
            $role = Role::updateOrCreate(['tenant_id' => null, 'slug' => $slug], ['name' => $name, 'description' => $description, 'is_system' => true]);
            $role->permissions()->sync(Permission::whereIn('slug', RolesAndPermissionsSeeder::expand($grants))->pluck('id'));
        }
    }

    public function down(): void
    {
        Role::whereNull('tenant_id')->whereIn('slug', [Role::MANAGER, Role::ACCOUNTANT])->get()->each(function (Role $role) {
            $role->permissions()->detach();
            $role->delete();
        });
    }
};
