<?php

namespace App\Modules\Attendance\Console;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\AttendanceCalendarService;
use App\Modules\Core\Models\Scopes\BranchScope;
use App\Modules\Core\Models\Scopes\TenantScope;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\HR\Models\EmployeeScheduleDay;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Limpia lo cargado antes de que la empresa empezara a usar el sistema (configuración y pruebas): la
 * programación de horarios (días libres o con turno) y las faltas sin ninguna marcación anteriores a --before.
 * Así el cierre del mes solo refleja lo real. Nunca toca un día con marcación real ni una corrección manual:
 * esos se listan y se dejan. Antes de borrar guarda una copia completa de cada fila (JSON en el disco privado)
 * y cada borrado queda en la bitácora de auditoría.
 *
 *   php artisan attendance:purge-before-go-live tunesaexpres --before=2026-11-16            (solo lista)
 *   php artisan attendance:purge-before-go-live tunesaexpres --before=2026-11-16 --apply
 */
class PurgeBeforeGoLive extends Command
{
    protected $signature = 'attendance:purge-before-go-live {tenant : Slug de la empresa}
                            {--before= : Primer día real (Y-m-d): se limpia lo anterior}
                            {--attendance= : Ids de faltas confirmadas como prueba (p. ej. 150,155): se quitan aunque tengan eventos de prueba o corrección, siempre sin entrada ni salida y antes de --before}
                            {--apply : Borrar (sin esto solo lista)}';

    protected $description = 'Limpia la programación y las faltas sin marcación de antes de que la empresa empezara a usar el sistema';

    public function handle(AuditLogger $audit): int
    {
        $before = (string) $this->option('before');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $before)) {
            $this->error('Indica --before=AAAA-MM-DD (primer día real de uso).');

            return self::FAILURE;
        }
        $tenant = Tenant::where('slug', $this->argument('tenant'))->first();
        if (! $tenant) {
            $this->info("No existe la empresa {$this->argument('tenant')}: nada que limpiar.");

            return self::SUCCESS;
        }

        $scoped = fn ($model) => $model::withoutGlobalScope(TenantScope::class)->withoutGlobalScope(BranchScope::class)
            ->where('tenant_id', $tenant->id)->where('work_date', '<', $before);
        $days = $scoped(EmployeeScheduleDay::class)->orderBy('work_date')->get();
        $confirmed = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('attendance')))));
        $absences = $scoped(Attendance::class)->whereNull('clock_in_at')->whereNull('clock_out_at')
            ->where(fn ($q) => $q->whereNull('manual_edited_at')->orWhereIn('id', $confirmed))->orderBy('work_date')->get();
        // Eventos de prueba en la bitácora de marcaciones: se van con su falta (borrado en cascada) y quedan en la copia
        $punches = DB::table('attendance_punches')->whereIn('attendance_id', $absences->pluck('id'))->orderBy('id')->get();
        if ($punches->isNotEmpty() && array_diff($punches->pluck('attendance_id')->unique()->all(), $confirmed)) {
            $this->error('Hay faltas con eventos en la bitácora de marcaciones que no se confirmaron con --attendance: no se borra nada.');

            return self::FAILURE;
        }
        $kept = $scoped(Attendance::class)->where(fn ($q) => $q->whereNotNull('clock_in_at')->orWhereNotNull('clock_out_at')->orWhereNotNull('manual_edited_at'))->count();

        $this->line("{$tenant->company_name}: antes del {$before} hay " . $days->count() . ' días de programación y ' . $absences->count()
            . " faltas sin marcación. Se dejan {$kept} jornadas con marcación o corrección manual.");
        foreach ($days as $d) {
            $this->line("  Programación · usuario {$d->user_id} · {$d->work_date->toDateString()} · " . ($d->shift_id ? "turno {$d->shift_id}" : 'libre'));
        }
        foreach ($absences as $a) {
            $this->line("  Falta · usuario {$a->user_id} · {$a->work_date->toDateString()} · {$a->status}");
        }

        if (! $this->option('apply') || ($days->isEmpty() && $absences->isEmpty())) {
            return self::SUCCESS;
        }

        // Copia completa antes de borrar (además del respaldo de la base que hace el despliegue)
        $copy = 'backups/purge-before-go-live/' . $tenant->slug . '-antes-de-' . $before . '-' . CarbonImmutable::now()->format('Ymd-His') . '.json';
        Storage::disk('private')->put($copy, json_encode([
            'tenant' => $tenant->only(['id', 'slug', 'company_name']), 'before' => $before,
            'employee_schedule_days' => $days->map->getAttributes()->values(),
            'attendances' => $absences->map->getAttributes()->values(),
            'attendance_punches' => $punches,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $reason = "Antes del inicio de uso del sistema ({$before})";
        DB::transaction(function () use ($days, $absences, $punches, $audit, $reason, $copy) {
            foreach ($days as $d) {
                $audit->log('schedule.pre_go_live_removed', $d, ['employee_id' => $d->user_id, 'work_date' => $d->work_date->toDateString(),
                    'shift_id' => $d->shift_id, 'reason' => $reason, 'backup' => $copy]);
                $d->delete();
            }
            foreach ($absences as $a) {
                $audit->log('attendance.pre_go_live_removed', $a, ['employee_id' => $a->user_id, 'work_date' => $a->work_date->toDateString(),
                    'status' => $a->status, 'test_punches' => $punches->where('attendance_id', $a->id)->count(), 'reason' => $reason, 'backup' => $copy]);
                $a->forceDelete();
            }
        });
        AttendanceCalendarService::bumpAll($tenant->id);

        $this->info('Borrados: ' . $days->count() . ' días de programación y ' . $absences->count() . " faltas. Copia: {$copy}");

        return self::SUCCESS;
    }
}
