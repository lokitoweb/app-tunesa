<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supervisión de varias empresas: el Super Admin asigna a una persona otras empresas del grupo además
 * de la suya (Configuración › Acceso a varias empresas). Sigue siendo de su empresa (ahí marca, su legajo
 * y su app); en el panel elige la empresa y en las asignadas actúa con su mismo rol.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_tenant_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'tenant_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tenant_access');
    }
};
