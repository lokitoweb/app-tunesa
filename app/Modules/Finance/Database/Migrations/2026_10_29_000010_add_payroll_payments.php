<?php

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pagos de RRHH (FrmIplanilla + FrmRegrrhh): cada línea de la planilla importada es un
 * requerimiento con el colaborador como beneficiario (no un "proveedor" duplicado) y pasa
 * por la misma Tesorería. Un colaborador no se paga dos veces el mismo periodo y concepto.
 */
return new class extends Migration
{
    private const PERMISSION = 'payroll.payments';

    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('supplier_id')->constrained('users')->nullOnDelete();
            $table->char('payroll_period', 7)->nullable()->after('employee_id')->comment('YYYY-MM');
            $table->string('payroll_concept', 20)->nullable()->after('payroll_period');
            $table->unique(['tenant_id', 'employee_id', 'payroll_period', 'payroll_concept'], 'requisitions_payroll_unique');
            // RQ y PL llevan correlativos propios en la misma serie: la unicidad es el código (tenant_id, code), que trae el prefijo
            $table->dropUnique(['tenant_id', 'series', 'year', 'number']);
        });

        [$name, $group] = RolesAndPermissionsSeeder::PERMISSIONS[self::PERMISSION];
        $id = Permission::updateOrCreate(['slug' => self::PERMISSION], ['name' => $name, 'group' => $group])->id;
        foreach (RolesAndPermissionsSeeder::ROLES as $roleSlug => [, , $grants]) {
            if (in_array(self::PERMISSION, RolesAndPermissionsSeeder::expand($grants), true)) {
                Role::whereNull('tenant_id')->where('slug', $roleSlug)->first()?->permissions()->syncWithoutDetaching([$id]);
            }
        }
    }

    public function down(): void
    {
        Permission::where('slug', self::PERMISSION)->get()->each(function (Permission $p) {
            $p->roles()->detach();
            $p->delete();
        });
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropUnique('requisitions_payroll_unique');
            $table->unique(['tenant_id', 'series', 'year', 'number']);
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn(['payroll_period', 'payroll_concept']);
        });
    }
};
