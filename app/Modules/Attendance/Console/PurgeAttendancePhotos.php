<?php

namespace App\Modules\Attendance\Console;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Services\Attendance\SelfieStorage;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Retención de selfies: borra los archivos (foto y miniatura) de las
 * marcaciones con más de --months meses. La bitácora no se toca salvo
 * photo_purged_at: photo_path y photo_sha256 quedan como constancia de que
 * hubo foto. En `attendances` se vacían clock_in_photo / clock_out_photo.
 *
 *   php artisan attendance:purge-photos
 *   php artisan attendance:purge-photos --months=6 --dry-run
 */
class PurgeAttendancePhotos extends Command
{
    public const DEFAULT_MONTHS = 12;

    protected $signature = 'attendance:purge-photos
                            {--months=' . self::DEFAULT_MONTHS . ' : Antigüedad mínima en meses}
                            {--dry-run : Solo cuenta, no borra}';

    protected $description = 'Borra las selfies de marcación más antiguas que la retención (12 meses por defecto)';

    public function handle(SelfieStorage $selfies, AuditLogger $audit): int
    {
        $months = (int) $this->option('months');
        if ($months < 1) {
            $this->error('--months debe ser al menos 1.');

            return self::INVALID;
        }

        $cutoff = now()->subMonths($months);
        $dryRun = (bool) $this->option('dry-run');
        $disk   = $selfies->disk();
        $byTenant = [];

        AttendancePunch::withoutGlobalScopes()
            ->whereNotNull('photo_path')
            ->whereNull('photo_purged_at')
            ->where('server_time', '<', $cutoff->copy()->utc())
            ->select(['id', 'tenant_id', 'photo_path'])
            ->chunkById(200, function ($punches) use ($selfies, $dryRun, &$byTenant) {
                foreach ($punches as $punch) {
                    $byTenant[$punch->tenant_id] = ($byTenant[$punch->tenant_id] ?? 0) + 1;
                    if (! $dryRun) {
                        $selfies->delete($punch->photo_path);
                    }
                }
                if (! $dryRun) {
                    AttendancePunch::markPhotoPurged($punches->pluck('id')->all());
                }
            });

        // Resumen de la jornada (comparte rutas con la bitácora; las previas a ella solo están aquí)
        Attendance::withoutGlobalScopes()
            ->where('work_date', '<', $cutoff->toDateString())
            ->where(fn ($q) => $q->whereNotNull('clock_in_photo')->orWhereNotNull('clock_out_photo'))
            ->select(['id', 'tenant_id', 'clock_in_photo', 'clock_out_photo'])
            ->chunkById(200, function ($attendances) use ($selfies, $disk, $dryRun) {
                foreach ($attendances as $attendance) {
                    if ($dryRun) {
                        continue;
                    }
                    foreach (array_filter([$attendance->clock_in_photo, $attendance->clock_out_photo]) as $path) {
                        $selfies->delete($path);
                        // Selfies anteriores a la Fase 3 quedaron en el disco público
                        if ($disk !== 'public') {
                            Storage::disk('public')->delete($path);
                        }
                    }
                    Attendance::withoutGlobalScopes()->whereKey($attendance->id)
                        ->update(['clock_in_photo' => null, 'clock_out_photo' => null]);
                }
            });

        $total = array_sum($byTenant);

        if (! $dryRun) {
            $context = app(TenantContext::class);
            foreach ($byTenant as $tenantId => $count) {
                if (! $tenant = Tenant::find($tenantId)) {
                    continue;
                }
                $context->set($tenant);
                $audit->log('attendance.photos_purged', $tenant, [
                    'photos' => $count,
                    'months' => $months,
                    'before' => $cutoff->toDateString(),
                ]);
                $context->clear();
            }
        }

        $this->info(($dryRun ? 'Se borrarían ' : 'Se borraron ') . "{$total} selfies de marcaciones anteriores al {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
