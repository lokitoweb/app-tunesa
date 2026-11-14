<?php

use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\DataCleanup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Limpieza pedida por el cliente el 2026-11-13 (DataCleanup), una sola vez al desplegar. El despliegue
 * respalda la base completa antes de migrar (scripts/deploy.sh › backup_db): ese respaldo es la vuelta atrás.
 * El resumen (cuántos registros de cada cosa) queda en la bitácora como «Limpieza de datos».
 */
return new class extends Migration
{
    public function up(): void
    {
        $done = app(DataCleanup::class)->run();
        // Instalación nueva o vacía: no había nada que limpiar, no se anota nada
        if (array_sum($done) === 0) {
            return;
        }

        Log::info('Limpieza de datos del cliente', $done);
        try {
            app(AuditLogger::class)->log('data.cleanup', null, ['resumen' => $done]);
        } catch (\Throwable $e) {
            Log::warning('Limpieza de datos: no se pudo anotar en la bitácora: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás desde aquí: se recupera con el respaldo que hizo el despliegue antes de migrar
    }
};
