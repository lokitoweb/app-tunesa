<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Límites de hora al marcar (Configuración › Reglas). NULL = hereda de la plataforma o el valor
 * predeterminado: entrada desde 1 min antes del horario, fin del refrigerio desde 1 min antes de
 * completarlo y salida desde la hora exacta (0). Con punch_time_limits apagado la entrada y la salida
 * quedan libres y el refrigerio vuelve a la regla de siempre (completo, sin margen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->boolean('punch_time_limits')->nullable()->comment('Bloquear marcaciones antes de la hora del horario');
            $table->unsignedSmallInteger('clock_in_early_minutes')->nullable()->comment('Minutos antes de la entrada en que ya se puede marcar');
            $table->unsignedSmallInteger('break_end_early_minutes')->nullable()->comment('Minutos antes de completar el refrigerio en que se puede marcar su fin');
            $table->unsignedSmallInteger('clock_out_early_minutes')->nullable()->comment('Minutos antes de la salida en que ya se puede marcar');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn(['punch_time_limits', 'clock_in_early_minutes', 'break_end_early_minutes', 'clock_out_early_minutes']);
        });
    }
};
