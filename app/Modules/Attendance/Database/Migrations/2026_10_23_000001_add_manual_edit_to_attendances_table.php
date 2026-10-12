<?php

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrección manual de marcaciones desde el panel: quién, cuándo y por qué
 * (el antes y después queda en la bitácora). Agrega el permiso attendance.edit
 * a los roles del sistema en las bases que ya existen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('manual_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('manual_edited_at')->nullable();
            $table->string('manual_edit_reason', 500)->nullable();
        });

        [$name, $group] = RolesAndPermissionsSeeder::PERMISSIONS['attendance.edit'];
        $permission = Permission::updateOrCreate(['slug' => 'attendance.edit'], ['name' => $name, 'group' => $group]);

        foreach (RolesAndPermissionsSeeder::ROLES as $roleSlug => [, , $grants]) {
            if (in_array('attendance.edit', RolesAndPermissionsSeeder::expand($grants), true)) {
                Role::whereNull('tenant_id')->where('slug', $roleSlug)->get()
                    ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]));
            }
        }
    }

    public function down(): void
    {
        Permission::where('slug', 'attendance.edit')->get()->each(function (Permission $p) {
            $p->roles()->detach();
            $p->delete();
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manual_edited_by');
            $table->dropColumn(['manual_edited_at', 'manual_edit_reason']);
        });
    }
};
