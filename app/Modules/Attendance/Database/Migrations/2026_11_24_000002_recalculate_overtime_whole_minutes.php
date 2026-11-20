<?php

use App\Modules\Core\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Las horas extra cuentan minutos completos, como las horas trabajadas (AttendanceService::minutesAfter). Antes, salir
 * unos segundos después de la hora (23:00:40) dejaba 1 minuto de hora extra «por aprobar» y le restaba ese minuto a la
 * jornada. Se recalculan con la fórmula única (attendance:recalculate --apply), igual que 2026_11_23_000001: solo
 * columnas calculadas; las horas extra ya aprobadas o rechazadas no se tocan. scripts/deploy.sh respalda la base antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Tenant::query()->pluck('id') as $id) {
            Artisan::call('attendance:recalculate', ['tenant' => (string) $id, '--from' => '2026-01-01', '--apply' => true]);
            Log::info('Horas extra en minutos completos: jornadas recalculadas', ['tenant_id' => $id, 'result' => trim(collect(explode("\n", Artisan::output()))->filter()->last() ?? '')]);
        }
    }

    public function down(): void
    {
        // Sin vuelta automática: los valores se recalculan con el comando cuando haga falta.
    }
};
