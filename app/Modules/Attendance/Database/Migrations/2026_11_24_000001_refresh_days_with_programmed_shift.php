<?php

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Jornadas juzgadas con un turno que ya no es el programado (p. ej. falta registrada con MAÑANA y luego
 * RR. HH. reprogramó el día a TARDE y cargó la entrada a mano): se recalculan con el turno programado
 * (AttendanceService::refreshDay). Solo las que tienen el turno distinto; las horas extra ya aprobadas o
 * rechazadas no se tocan. Cada una queda en la bitácora con el antes y el después. El deploy respalda la base antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $context = app(TenantContext::class);
        $audit = app(AuditLogger::class);

        foreach (Tenant::all() as $tenant) {
            $context->set($tenant);
            $service = app(AttendanceService::class);
            $fixed = 0;

            Attendance::withoutGlobalScopes()->where('tenant_id', $tenant->id)
                ->where('work_date', '>=', '2026-01-01')->whereNotNull('clock_in_at')
                ->with(['user.tenant', 'shift'])->chunkById(500, function ($chunk) use ($service, $audit, &$fixed) {
                    foreach ($chunk as $a) {
                        if (! $a->user) {
                            continue;
                        }
                        $shift = $service->dayShift($a->user, $a->work_date->toDateString(), $a);
                        if ((int) $shift?->id === (int) $a->shift_id) {
                            continue;
                        }
                        $before = $a->only(['shift_id', 'late_minutes', 'worked_hours', 'overtime_hours', 'early_exit_minutes', 'status', 'overtime_status']);
                        if ($service->refreshDay($a, keepReviewedOvertime: true)) {
                            $fixed++;
                            $audit->logChanges('attendance.recalculated', $a, $before, $a->only(array_keys($before)), [
                                'employee_id' => $a->user_id, 'work_date' => $a->work_date->toDateString(),
                                'reason' => 'Recalculada con el turno programado del día',
                            ]);
                        }
                    }
                });

            Log::info("refresh_days_with_programmed_shift: {$tenant->slug} {$fixed} jornada(s) recalculada(s)");
        }
        $context->clear();
    }

    public function down(): void
    {
        // Sin vuelta atrás automática: el respaldo del deploy y la bitácora (antes y después) permiten revisarlo
    }
};
