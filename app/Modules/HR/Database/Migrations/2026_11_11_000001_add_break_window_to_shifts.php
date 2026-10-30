<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA3-04 · 4 marcaciones: el horario puede fijar la salida y el retorno del refrigerio
 * (entrada · salida a refrigerio · retorno · salida). Opcionales: los horarios que ya
 * existían quedan sin ellos y siguen con 2 marcaciones, sin cambiar nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->time('break_start_time')->nullable()->after('break_duration_minutes')->comment('Salida a refrigerio (HH:MM)');
            $table->time('break_end_time')->nullable()->after('break_start_time')->comment('Retorno de refrigerio (HH:MM)');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['break_start_time', 'break_end_time']);
        });
    }
};
