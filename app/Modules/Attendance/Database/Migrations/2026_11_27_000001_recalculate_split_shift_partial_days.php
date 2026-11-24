<?php

use App\Modules\Core\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Horario partido: la pausa entre tramos se descontaba entera como descanso fijo (p. ej. 405 min en 06:30–11:45 ·
 * 18:30–21:30); quien trabajaba solo el 1.er tramo (06:48–12:07) quedaba con 0 h de jornada. Ahora se descuenta solo
 * la parte de la pausa que cae dentro de lo trabajado (AttendanceService::closedDayHours). Se recalculan las jornadas
 * con la fórmula única (attendance:recalculate --apply), igual que 2026_11_24_000002: solo columnas calculadas; las
 * horas extra ya aprobadas o rechazadas no se tocan. scripts/deploy.sh respalda la base antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Tenant::query()->pluck('id') as $id) {
            Artisan::call('attendance:recalculate', ['tenant' => (string) $id, '--from' => '2026-01-01', '--apply' => true]);
            Log::info('Horario partido: jornadas recalculadas', ['tenant_id' => $id, 'result' => trim(collect(explode("\n", Artisan::output()))->filter()->last() ?? '')]);
        }
    }

    public function down(): void
    {
        // Sin vuelta automática: los valores se recalculan con el comando cuando haga falta.
    }
};
