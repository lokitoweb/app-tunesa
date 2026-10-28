<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles propios de cada empresa ("Jefe de flota", "Asistente contable"…). Cada uno se basa en un rol
 * del sistema (roles.base_role): la persona conserva ese rol base en users.role para todo lo que ya
 * funciona (jerarquía, panel, app) y su rol propio (users.custom_role_id) define sus permisos exactos:
 * los del rol base ajustados con la misma diferencia de role_permission_overrides. Sin rol propio,
 * nada cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('base_role', 50)->nullable()->after('slug')->comment('Rol del sistema en que se basa un rol de empresa');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('custom_role_id')->nullable()->after('role')->constrained('roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('custom_role_id'));
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('base_role'));
    }
};
