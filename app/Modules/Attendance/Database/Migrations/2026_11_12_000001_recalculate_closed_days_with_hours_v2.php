<?php

use App\Modules\Core\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Bloque 2 (11/2026): hours_v2 pasa a ser la regla de todas las empresas y el reporte muestra lo guardado.
 * Las jornadas cerradas guardadas con la regla anterior se recalculan con la fórmula única
 * (attendance:recalculate --apply). Solo cambia columnas calculadas (horas, extras por aprobar, estado);
 * las extras ya aprobadas o rechazadas no se tocan. scripts/deploy.sh respalda la base antes de migrar.
 * Vuelta atrás por empresa: attendance:flag <empresa> hours_v2 off + attendance:recalculate <empresa> --apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Tenant::query()->pluck('id') as $id) {
            Artisan::call('attendance:recalculate', ['tenant' => (string) $id, '--from' => '2026-01-01', '--apply' => true]);
            Log::info('hours_v2: jornadas recalculadas', ['tenant_id' => $id, 'result' => trim(collect(explode("\n", Artisan::output()))->filter()->last() ?? '')]);
        }
    }

    public function down(): void
    {
        // Sin vuelta automática: los valores se recalculan con el comando cuando haga falta.
    }
};
