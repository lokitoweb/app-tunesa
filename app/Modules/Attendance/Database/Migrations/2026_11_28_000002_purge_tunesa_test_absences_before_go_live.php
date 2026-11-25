<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Tunesa, 15/11/2026 (domingo, antes del inicio de uso): las faltas #150 (Sandy Polo) y #155 (Luz María Tejada)
 * vienen de marcaciones de prueba (entrada y salida con segundos de diferencia, sin foto ni GPS). La limpieza
 * anterior las dejó por tener eventos en la bitácora de marcaciones; el dueño confirmó quitarlas. Solo esas dos
 * (misma empresa, antes del 16/11, sin entrada ni salida): sus eventos de prueba van a la copia JSON y cada
 * borrado queda en la bitácora. scripts/deploy.sh respalda la base completa antes de migrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('attendance:purge-before-go-live', ['tenant' => 'tunesaexpres', '--before' => '2026-11-16', '--attendance' => '150,155', '--apply' => true]);
        Log::info('Tunesa, faltas de prueba del 15/11: ' . trim(Artisan::output()));
    }

    public function down(): void
    {
        // Sin vuelta automática: la copia JSON (storage privado, backups/purge-before-go-live) y el respaldo de la base la permiten.
    }
};
