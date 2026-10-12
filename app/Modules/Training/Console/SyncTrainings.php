<?php

namespace App\Modules\Training\Console;

use App\Modules\Core\Models\Scopes\TenantScope;
use App\Modules\Training\Models\TrainingCourse;
use App\Modules\Training\Models\TrainingEnrollment;
use App\Modules\Training\Notifications\TrainingDueSoon;
use App\Modules\Training\Services\TrainingEnrollmentService;
use Illuminate\Console\Command;

/**
 * Cada madrugada:
 * - vuelve a calcular a quién le toca cada curso publicado (altas, cambios de
 *   sucursal, área o cargo hechos por cualquier vía);
 * - avisa a quien tiene una capacitación que vence en DUE_SOON_DAYS días o menos.
 *
 *   php artisan training:sync
 */
class SyncTrainings extends Command
{
    public const DUE_SOON_DAYS = 2;

    protected $signature = 'training:sync';

    protected $description = 'Asigna capacitaciones a quien le corresponden y avisa las que vencen pronto';

    public function handle(TrainingEnrollmentService $service): int
    {
        $new = 0;
        TrainingCourse::withoutGlobalScope(TenantScope::class)->published()->with('targets')
            ->each(function (TrainingCourse $course) use ($service, &$new) {
                $new += $service->syncCourse($course);
            });

        $reminded = 0;
        TrainingEnrollment::withoutGlobalScope(TenantScope::class)
            ->whereNull('completed_at')->whereNull('due_reminder_sent_at')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '>=', now()->toDateString())
            ->whereDate('due_date', '<=', now()->addDays(self::DUE_SOON_DAYS)->toDateString())
            ->whereHas('course', fn ($q) => $q->withoutGlobalScope(TenantScope::class)->published())
            ->whereHas('user', fn ($q) => $q->withoutGlobalScope(TenantScope::class)->where('status', 'active'))
            ->with(['course' => fn ($q) => $q->withoutGlobalScope(TenantScope::class), 'user' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)])
            ->each(function (TrainingEnrollment $e) use (&$reminded) {
                $e->user->notify(new TrainingDueSoon($e));
                $e->forceFill(['due_reminder_sent_at' => now()])->saveQuietly();
                $reminded++;
            });

        $this->info("Capacitaciones: {$new} asignaciones nuevas, {$reminded} recordatorios de vencimiento.");

        return self::SUCCESS;
    }
}
