<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Faltas automáticas que quedaron en días que después se programaron como «libre» (el calendario mostraba
 * «Faltó» contra la programación). Desde ahora se anulan al guardar la programación; esto repara las de antes
 * con attendance:void-absences-on-days-off. Solo días con fila «libre» en la programación y faltas que puso el
 * sistema (sin marcación, sin corrección manual, sin justificación); cada una queda en la bitácora de auditoría.
 * scripts/deploy.sh respalda la base antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('attendance:void-absences-on-days-off', ['--apply' => true]);
        Log::info('Faltas en días programados como libres: ' . trim(Artisan::output()));
    }

    public function down(): void
    {
        // Sin vuelta automática: lo anulado queda en la bitácora de auditoría (attendance.absence_voided).
    }
};
