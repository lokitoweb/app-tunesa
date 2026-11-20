<?php

use App\Modules\Core\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Regla del cliente (11/2026): la jornada se cuenta desde la hora de entrada del horario; lo marcado antes no
 * es jornada (AttendanceService::closedDayHours). Las jornadas cerradas se recalculan con la fórmula única
 * (attendance:recalculate --apply), igual que en 2026_11_12. Solo cambian columnas calculadas; las horas extra
 * ya aprobadas o rechazadas no se tocan. scripts/deploy.sh respalda la base antes de migrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Tenant::query()->pluck('id') as $id) {
            Artisan::call('attendance:recalculate', ['tenant' => (string) $id, '--from' => '2026-01-01', '--apply' => true]);
            Log::info('Jornada desde la hora de entrada: jornadas recalculadas', ['tenant_id' => $id, 'result' => trim(collect(explode("\n", Artisan::output()))->filter()->last() ?? '')]);
        }
    }

    public function down(): void
    {
        // Sin vuelta automática: los valores se recalculan con el comando cuando haga falta.
    }
};
