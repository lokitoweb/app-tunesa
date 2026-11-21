<?php

namespace App\Modules\Attendance\Console;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\AbsenceService;
use Illuminate\Console\Command;

/**
 * Anula las faltas automáticas de días que la Programación de horarios tiene como «libre» (AbsenceService::voidOnDaysOff).
 * Al guardar la programación ya se anulan solas; esto repara las que quedaron de antes.
 *
 *   php artisan attendance:void-absences-on-days-off            (solo cuenta)
 *   php artisan attendance:void-absences-on-days-off --apply
 */
class VoidAbsencesOnDaysOff extends Command
{
    protected $signature = 'attendance:void-absences-on-days-off {--apply : Anular (sin esto solo cuenta)}';

    protected $description = 'Anula las faltas automáticas de días programados como libres';

    public function handle(AbsenceService $absences): int
    {
        $apply = (bool) $this->option('apply');
        $count = $absences->voidOnDaysOff(Attendance::withoutGlobalScope(\App\Modules\Core\Models\Scopes\TenantScope::class)->withoutGlobalScope(\App\Modules\Core\Models\Scopes\BranchScope::class), 'Día programado como libre (reparación)', dryRun: ! $apply);
        $this->info(($apply ? 'Faltas anuladas: ' : 'Faltas por anular (usa --apply): ') . $count);

        return self::SUCCESS;
    }
}
