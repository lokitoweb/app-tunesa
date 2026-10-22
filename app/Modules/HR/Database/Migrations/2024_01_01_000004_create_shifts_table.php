<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: shifts (Turnos de Trabajo)
 * Define las plantillas de horarios laborales que se asignan a los empleados.
 * Ejemplo: "Turno Mañana 08:00-17:00", "Turno Noche 22:00-06:00".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();

            // === AISLAMIENTO MULTI-TENANT ===
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // === DATOS DEL TURNO ===
            $table->string('name')->comment('Nombre descriptivo del turno');
            $table->string('code', 50)->nullable()->comment('Código corto del turno (ej: T-MAÑ)');

            // Horario de entrada y salida esperados
            $table->time('start_time')->comment('Hora de entrada programada (HH:MM)');
            $table->time('end_time')->comment('Hora de salida programada (HH:MM)');

            // Turno nocturno: puede cruzar medianoche
            $table->boolean('crosses_midnight')->default(false)
                ->comment('TRUE si el turno termina al día siguiente (turno noche)');

            // Tolerancia de tardanza en minutos antes de marcar como "tarde"
            $table->unsignedSmallInteger('grace_period_minutes')
                ->default(10)
                ->comment('Minutos de tolerancia antes de registrar tardanza');

            // Configuración de break/descanso
            $table->boolean('break_allowed')->default(true);
            $table->unsignedSmallInteger('break_duration_minutes')
                ->default(60)
                ->comment('Duración máxima del descanso en minutos');

            // Días laborables (almacenado como JSON: [1,2,3,4,5] = Lun-Vie)
            // 0=Domingo, 1=Lunes, ..., 6=Sábado
            // Default como expresión: MySQL 8 no acepta un literal en columnas JSON (error 1101);
            // ('...') vale en MySQL 8.0.13+, MariaDB, SQLite y PostgreSQL.
            $table->json('working_days')
                ->default(DB::raw("('[1,2,3,4,5]')"))
                ->comment('Array de días: 0=Dom, 1=Lun, 2=Mar, 3=Mié, 4=Jue, 5=Vie, 6=Sáb');

            // Horas laborables por día (para cálculo de nómina)
            $table->decimal('daily_hours', 4, 2)->default(8.00);

            // Color para mostrar en el calendario del panel web
            $table->string('color', 10)->default('#3B82F6')->comment('Color hex para el calendario');

            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // === ÍNDICES ===
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
