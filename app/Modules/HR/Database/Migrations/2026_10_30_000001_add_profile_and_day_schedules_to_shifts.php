<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turnos por semana (DEF-01).
 *
 * - profile:       perfil del turno (administrativo, ventas, operaciones) para
 *                  agrupar y asignar sin confundir horarios.
 * - day_schedules: horario distinto en ciertos días de la semana, p. ej.
 *                  {"6": {"start_time": "08:00", "end_time": "13:00",
 *                  "break_duration_minutes": 0, "daily_hours": 5}}.
 *                  Los días sin entrada usan el horario general del turno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->string('profile', 20)->nullable()->after('type');
            $table->json('day_schedules')->nullable()->after('working_days');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['profile', 'day_schedules']);
        });
    }
};
