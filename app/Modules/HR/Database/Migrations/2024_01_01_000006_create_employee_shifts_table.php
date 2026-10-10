<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: employee_shift (Asignación de Turno a Empleado)
 * Relaciona un empleado con su turno de trabajo en un período dado.
 * Permite cambios de turno con fechas de inicio/fin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_shifts', function (Blueprint $table) {
            $table->id();

            // === AISLAMIENTO MULTI-TENANT ===
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('shift_id')
                ->constrained('shifts')
                ->cascadeOnDelete();

            // Sucursal donde aplica este turno (puede diferir de la asignación general)
            $table->foreignId('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();

            // Vigencia del turno asignado
            $table->date('effective_from')->comment('Fecha desde que aplica este turno');
            $table->date('effective_until')->nullable()->comment('NULL = turno indefinido');

            // Quién asignó el turno
            $table->foreignId('assigned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // === ÍNDICES ===
            $table->index(['tenant_id', 'user_id', 'is_active']);
            $table->index(['tenant_id', 'branch_id']);
            $table->index(['effective_from', 'effective_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_shifts');
    }
};
