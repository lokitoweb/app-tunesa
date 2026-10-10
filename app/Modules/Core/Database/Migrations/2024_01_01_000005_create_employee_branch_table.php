<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: employee_branch (Pivot)
 * Asigna empleados a sucursales específicas.
 * Un empleado puede estar asignado a múltiples sucursales (ej: trabaja en dos locales).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_branch', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();

            // Sucursal principal del empleado
            $table->boolean('is_primary')->default(false)
                ->comment('TRUE si es la sucursal principal del empleado');

            // Fechas de asignación
            $table->date('assigned_from')->nullable();
            $table->date('assigned_until')->nullable();

            $table->timestamps();

            // Un empleado solo puede estar asignado una vez a la misma sucursal
            $table->unique(['user_id', 'branch_id']);
            $table->index(['branch_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_branch');
    }
};
