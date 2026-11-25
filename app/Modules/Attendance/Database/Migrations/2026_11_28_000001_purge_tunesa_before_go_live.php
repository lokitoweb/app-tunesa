<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Tunesa empezó a usar el sistema el 16/11/2026. Lo cargado antes (configuración y pruebas: 41 días «libre»
 * programados del 11 al 15/11 y 2 faltas sin marcación del 15/11) salía en el cierre de noviembre. Se limpia con
 * attendance:purge-before-go-live, que no toca días con marcación real ni correcciones manuales, guarda una copia
 * JSON de cada fila y deja cada borrado en la bitácora. scripts/deploy.sh respalda la base completa antes de migrar.
 * En otra instalación sin esa empresa no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('attendance:purge-before-go-live', ['tenant' => 'tunesaexpres', '--before' => '2026-11-16', '--apply' => true]);
        Log::info('Tunesa antes del inicio de uso: ' . trim(Artisan::output()));
    }

    public function down(): void
    {
        // Sin vuelta automática: la copia JSON (storage privado, backups/purge-before-go-live) y el respaldo de la base la permiten.
    }
};
