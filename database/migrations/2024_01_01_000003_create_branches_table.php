<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: branches (Sucursales / Obras)
 * Cada empresa puede tener múltiples sucursales con su geocerca definida.
 * El GPS del empleado se valida contra el radio de la sucursal asignada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();

            // === AISLAMIENTO MULTI-TENANT ===
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // === DATOS DE LA SUCURSAL ===
            $table->string('name')->comment('Nombre de la sucursal u obra');
            $table->string('code', 50)->nullable()->comment('Código interno de la sucursal');
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country', 100)->default('Peru');

            // === DATOS DE GEOCERCA (GPS) ===
            // Latitud: -90 a +90 (precisión de 8 decimales = ~1mm de precisión)
            $table->decimal('latitude', 10, 8)
                ->comment('Latitud del centro de la geocerca');

            // Longitud: -180 a +180
            $table->decimal('longitude', 11, 8)
                ->comment('Longitud del centro de la geocerca');

            // Radio en metros permitido para registrar asistencia
            $table->unsignedInteger('allowed_radius_meters')
                ->default(50)
                ->comment('Radio máximo en metros para validar la ubicación del empleado');

            // === CONFIGURACIÓN DE ZONA HORARIA DE LA SUCURSAL ===
            // Puede diferir de la zona horaria del tenant (empresa multi-país)
            $table->string('timezone', 50)->nullable()->comment('Override de timezone para esta sucursal');

            // === ESTADO ===
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            // Supervisor responsable de esta sucursal
            $table->foreignId('supervisor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // === ÍNDICES ===
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'code']);

            // Índice espacial básico para consultas por zona geográfica
            $table->index(['latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
