<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jerarquía organizacional: Empresa → Zona → Sede (branches) → Departamento,
 * y alcance territorial de cada usuario (toda la empresa, una zona o una sede).
 *
 * El radio de marcación de la sede sigue siendo branches.allowed_radius_meters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('zone_id')->nullable()->after('tenant_id')->constrained('zones')->nullOnDelete();
            $table->string('branch_type', 20)->default('agency')->after('code');

            // Listados y filtros en cascada Zona → Sede dentro de la empresa
            $table->index(['tenant_id', 'zone_id', 'id'], 'branches_tenant_zone_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();

            // Alcance: global = toda la empresa; zone / branch = solo esa zona o sede
            $table->string('scope_type', 10)->default('global');
            $table->foreignId('scope_zone_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->foreignId('scope_branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // Itinerantes (choferes, relevos, técnicos): marcan en cualquier sede o en ruta
            $table->boolean('roaming_attendance_enabled')->default(false);

            $table->index(['tenant_id', 'scope_zone_id', 'scope_branch_id'], 'users_tenant_scope_idx');
            $table->index(['tenant_id', 'department_id'], 'users_tenant_department_idx');
        });

        // El scope por sede consulta employee_branch por colaborador en cada listado
        Schema::table('employee_branch', function (Blueprint $table) {
            $table->index(['user_id', 'branch_id'], 'employee_branch_user_branch_idx');
        });
    }

    public function down(): void
    {
        Schema::table('employee_branch', fn (Blueprint $table) => $table->dropIndex('employee_branch_user_branch_idx'));

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_tenant_scope_idx');
            $table->dropIndex('users_tenant_department_idx');
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('scope_zone_id');
            $table->dropConstrainedForeignId('scope_branch_id');
            $table->dropColumn(['scope_type', 'roaming_attendance_enabled']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropIndex('branches_tenant_zone_idx');
            $table->dropConstrainedForeignId('zone_id');
            $table->dropColumn('branch_type');
        });

        Schema::dropIfExists('departments');
        Schema::dropIfExists('zones');
    }
};
