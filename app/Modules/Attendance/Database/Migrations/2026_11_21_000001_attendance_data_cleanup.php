<?php

use App\Modules\Attendance\Services\AttendanceDataCleanup;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Limpieza de la asistencia de prueba pedida por el cliente el 2026-11-14 (AttendanceDataCleanup), una
 * sola vez al desplegar. El despliegue respalda la base completa antes de migrar (scripts/deploy.sh ›
 * backup_db): ese respaldo es la vuelta atrás. El resumen queda en la bitácora como «Limpieza de datos».
 */
return new class extends Migration
{
    public function up(): void
    {
        $done = app(AttendanceDataCleanup::class)->run();
        if (array_sum($done) === 0) {
            return;
        }

        Log::info('Limpieza de asistencia de prueba', $done);
        try {
            app(AuditLogger::class)->log('data.cleanup', null, ['resumen' => $done]);
        } catch (\Throwable $e) {
            Log::warning('Limpieza de asistencia: no se pudo anotar en la bitácora: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás desde aquí: se recupera con el respaldo que hizo el despliegue antes de migrar
    }
};
