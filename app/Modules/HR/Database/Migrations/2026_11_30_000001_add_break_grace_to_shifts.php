<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tareo por colaborador (aprobado por el ingeniero, 26/11/2026): la tolerancia del horario es solo
 * para la entrada; el retorno del refrigerio tiene la suya, administrable por horario y en 0 por
 * defecto. Las jornadas ya cerradas no se recalculan: la regla rige desde las nuevas marcaciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shifts', 'break_grace_minutes')) {
            Schema::table('shifts', function (Blueprint $table) {
                $table->unsignedSmallInteger('break_grace_minutes')->default(0)->after('grace_period_minutes')
                    ->comment('Tolerancia del retorno del refrigerio (min); la de grace_period_minutes es solo para la entrada');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shifts', 'break_grace_minutes')) {
            Schema::table('shifts', function (Blueprint $table) {
                $table->dropColumn('break_grace_minutes');
            });
        }
    }
};
