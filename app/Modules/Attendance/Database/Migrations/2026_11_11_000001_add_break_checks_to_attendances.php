<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA3-04 · 4 marcaciones: minutos de retorno tardío del refrigerio y aviso de jornada con
 * marcaciones incompletas (horario de 4 marcaciones y faltó la salida o el retorno del
 * refrigerio). El estado de la jornada (puntual, tardanza...) no cambia por esto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->unsignedSmallInteger('break_late_minutes')->default(0)->after('break_minutes');
            $table->boolean('punches_incomplete')->default(false)->after('break_late_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['break_late_minutes', 'punches_incomplete']);
        });
    }
};
