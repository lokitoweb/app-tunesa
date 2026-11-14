<?php

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traslado de un colaborador a otra empresa del grupo (EmployeeTransfer): en la de origen queda su
 * cese con toda su historia; en la de destino, un registro nuevo con sus datos personales y el mismo
 * acceso (correo y contraseña). Esta tabla enlaza ambos registros. Permiso employees.transfer: RR. HH.
 * y Super Admin; solo el Super Admin lo da a otros roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('to_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('effective_date')->comment('Primer día en la empresa de destino');
            $table->unsignedSmallInteger('documents_copied')->default(0);
            $table->boolean('onboarding_required')->default(false);
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('from_user_id');
            $table->index('to_user_id');
        });

        [$name, $group] = RolesAndPermissionsSeeder::PERMISSIONS['employees.transfer'];
        $id = Permission::updateOrCreate(['slug' => 'employees.transfer'], ['name' => $name, 'group' => $group])->id;
        foreach (RolesAndPermissionsSeeder::ROLES as $roleSlug => [, , $grants]) {
            if (in_array('employees.transfer', RolesAndPermissionsSeeder::expand($grants), true)) {
                Role::whereNull('tenant_id')->where('slug', $roleSlug)->get()
                    ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$id]));
            }
        }
    }

    public function down(): void
    {
        Permission::where('slug', 'employees.transfer')->get()->each(function (Permission $p) {
            $p->roles()->detach();
            $p->delete();
        });
        Schema::dropIfExists('employee_transfers');
    }
};
