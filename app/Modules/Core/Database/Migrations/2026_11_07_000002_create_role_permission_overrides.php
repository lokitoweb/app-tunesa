<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permisos de cada rol personalizados por empresa (Configuración › Roles y permisos).
 *
 * Se guarda la DIFERENCIA con el rol estándar del sistema, no una copia: permiso efectivo =
 * estándar + concedidos − quitados. Así un permiso nuevo del sistema (un módulo nuevo) llega solo
 * a todas las empresas, y un despliegue (que resincroniza el estándar) nunca pisa lo que cada
 * empresa decidió. Sin filas = la empresa usa el estándar tal cual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('role', 50)->comment('slug del rol del sistema (users.role)');
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->boolean('granted')->comment('true = se agrega al rol, false = se le quita');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'role', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permission_overrides');
    }
};
