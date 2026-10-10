<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: attendances (Registros de Asistencia)
 * Tabla central del sistema. Almacena cada marcación del empleado.
 * Incluye GPS, foto selfie y estado calculado (on_time, late, absent, etc.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();

            // === AISLAMIENTO MULTI-TENANT ===
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // === RELACIONES PRINCIPALES ===
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->comment('Empleado que registra la asistencia');

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->comment('Sucursal donde se registra');

            $table->foreignId('shift_id')
                ->nullable()
                ->constrained('shifts')
                ->nullOnDelete()
                ->comment('Turno programado para ese día');

            // === FECHA DE TRABAJO ===
            // Separado del timestamp para facilitar reportes diarios
            $table->date('work_date')->comment('Fecha laboral del registro (ej: si turno nocturno cruza medianoche)');

            // === MARCACIONES (4 EVENTOS) ===

            // 1. CLOCK-IN (Entrada)
            $table->timestamp('clock_in_at')->nullable()->comment('Timestamp exacto de entrada');
            $table->decimal('clock_in_latitude', 10, 8)->nullable();
            $table->decimal('clock_in_longitude', 11, 8)->nullable();
            $table->unsignedInteger('clock_in_distance_meters')->nullable()
                ->comment('Distancia calculada al marcar entrada (Haversine)');
            $table->string('clock_in_photo')->nullable()
                ->comment('Ruta de la foto selfie de entrada en Storage');
            $table->string('clock_in_device_id')->nullable()
                ->comment('Device ID del móvil al marcar entrada');
            $table->json('clock_in_meta')->nullable()
                ->comment('Metadatos extra: IP, OS, accuracy del GPS, etc.');

            // 2. BREAK START (Inicio de descanso)
            $table->timestamp('break_start_at')->nullable();
            $table->decimal('break_start_latitude', 10, 8)->nullable();
            $table->decimal('break_start_longitude', 11, 8)->nullable();

            // 3. BREAK END (Fin de descanso)
            $table->timestamp('break_end_at')->nullable();
            $table->decimal('break_end_latitude', 10, 8)->nullable();
            $table->decimal('break_end_longitude', 11, 8)->nullable();

            // 4. CLOCK-OUT (Salida)
            $table->timestamp('clock_out_at')->nullable()->comment('Timestamp exacto de salida');
            $table->decimal('clock_out_latitude', 10, 8)->nullable();
            $table->decimal('clock_out_longitude', 11, 8)->nullable();
            $table->unsignedInteger('clock_out_distance_meters')->nullable();
            $table->string('clock_out_photo')->nullable()
                ->comment('Ruta de la foto selfie de salida en Storage');
            $table->string('clock_out_device_id')->nullable();
            $table->json('clock_out_meta')->nullable();

            // === ESTADO Y CÁLCULOS ===
            $table->enum('status', [
                'present',          // Presente a tiempo
                'late',             // Tardanza
                'absent',           // Ausente sin justificar
                'excused',          // Ausente justificado
                'half_day',         // Medio día
                'holiday',          // Día feriado
                'day_off',          // Franco / día libre
                'incomplete',       // Marcó entrada pero no salida
            ])->default('incomplete');

            // Minutos de tardanza (0 si llegó a tiempo)
            $table->unsignedSmallInteger('late_minutes')->default(0);

            // Minutos de break tomado
            $table->unsignedSmallInteger('break_minutes')->default(0)
                ->comment('Minutos totales de descanso (break_end - break_start)');

            // Horas trabajadas efectivas
            $table->decimal('worked_hours', 5, 2)->default(0)
                ->comment('Horas netas trabajadas (clock_out - clock_in - break)');

            // Horas extra
            $table->decimal('overtime_hours', 5, 2)->default(0);

            // === VALIDACIÓN GPS ===
            $table->boolean('clock_in_within_geofence')->default(false)
                ->comment('TRUE si el clock-in pasó la validación de geocerca');
            $table->boolean('clock_out_within_geofence')->default(false);

            // === JUSTIFICACIONES / INCIDENCIAS ===
            $table->text('notes')->nullable()->comment('Notas del empleado o del supervisor');
            $table->string('justification_file')->nullable()
                ->comment('Archivo de justificación subido por el empleado');
            $table->enum('justification_status', [
                'none',         // Sin justificación
                'pending',      // Pendiente de aprobación
                'approved',     // Aprobada
                'rejected',     // Rechazada
            ])->default('none');

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Supervisor/HR que revisó la justificación');

            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // === ÍNDICES CRÍTICOS PARA PERFORMANCE ===
            // Consultas más frecuentes del sistema
            $table->index(['tenant_id', 'user_id', 'work_date']);
            $table->index(['tenant_id', 'branch_id', 'work_date']);
            $table->index(['tenant_id', 'status', 'work_date']);
            $table->index(['tenant_id', 'work_date']); // Para dashboard en tiempo real

            // Un empleado solo puede tener UN registro de asistencia por día por sucursal
            $table->unique(['user_id', 'branch_id', 'work_date'], 'unique_attendance_per_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
