<?php

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correlativos formales RQ/OC/OS-[EMPRESA]-[SERIE]-[N]-[AÑO]:
 * - tenants.code: abreviatura única de 2 caracteres (se genera para las empresas que ya existen).
 * - document_sequences: último número por empresa, tipo, serie y año (se reinicia cada año).
 * - requisitions.code/year: el código queda fijo al emitirse; los antiguos conservan su formato.
 * - Permiso company.document_code para editar la abreviatura.
 */
return new class extends Migration
{
    private const PERMISSION = 'company.document_code';

    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('code', 2)->nullable()->unique()->comment('Abreviatura en los correlativos');
        });
        Tenant::withTrashed()->whereNull('code')->orderBy('id')->each(
            fn (Tenant $t) => $t->forceFill(['code' => Tenant::uniqueCode($t->company_name, $t->id)])->saveQuietly()
        );

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 4)->comment('RQ | OC | OS');
            $table->string('series', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'type', 'series', 'year']);
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->string('code', 60)->nullable()->after('number');
            $table->unsignedSmallInteger('year')->nullable()->after('code');
            $table->dropUnique(['tenant_id', 'series', 'number']);
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'series', 'year', 'number']);
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
            $table->dropUnique(['tenant_id', 'series', 'year', 'number']);
            $table->dropUnique(['tenant_id', 'code']);
            $table->dropColumn(['code', 'year']);
            $table->unique(['tenant_id', 'series', 'number']);
        });
        Schema::dropIfExists('document_sequences');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
