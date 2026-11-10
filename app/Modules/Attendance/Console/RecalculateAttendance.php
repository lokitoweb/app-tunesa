<?php

namespace App\Modules\Attendance\Console;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Vuelve a calcular las jornadas cerradas con la fórmula única (AttendanceService::closedDayHours), p. ej.
 * tras cambiar la bandera hours_v2 o un turno. Sin --apply solo muestra las diferencias.
 * Las horas extra ya aprobadas o rechazadas no se tocan: se listan para que RR. HH. las revise.
 *
 *   php artisan attendance:recalculate empresa-demo --from=2026-10-01 --to=2026-11-10
 *   php artisan attendance:recalculate empresa-demo --from=2026-10-01 --apply
 */
class RecalculateAttendance extends Command
{
    protected $signature = 'attendance:recalculate
                            {tenant : Id o slug de la empresa}
                            {--from= : Desde (Y-m-d). Por defecto: hace 60 días}
                            {--to= : Hasta (Y-m-d). Por defecto: hoy}
                            {--apply : Guardar los cambios (sin esto solo se muestran)}';

    protected $description = 'Recalcula horas, extras y estado de jornadas cerradas con la fórmula vigente';

    public function handle(AttendanceService $service, TenantContext $context, AuditLogger $audit): int
    {
        $key = (string) $this->argument('tenant');
        $tenant = Tenant::where('slug', $key)->when(ctype_digit($key), fn ($q) => $q->orWhere('id', (int) $key))->first();
        if (! $tenant) {
            $this->error("No existe la empresa {$key}.");

            return self::FAILURE;
        }
        $context->set($tenant);
        $tz = $tenant->timezone ?: config('app.timezone');
        $from = $this->option('from') ?: now($tz)->subDays(60)->toDateString();
        $to = $this->option('to') ?: now($tz)->toDateString();
        $settings = TenantSetting::forTenant($tenant);
        $apply = (bool) $this->option('apply');

        $rows = [];
        $changed = $review = 0;
        Attendance::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->whereBetween('work_date', [$from, $to])->whereNotNull('clock_in_at')->whereNotNull('clock_out_at')
            ->with(['user', 'shift'])->orderBy('work_date')->chunkById(500, function ($chunk) use ($service, $tenant, $tz, $settings, $apply, &$rows, &$changed, &$review) {
                foreach ($chunk as $a) {
                    if (! $a->user) {
                        continue;
                    }
                    $date = $a->work_date->toDateString();
                    $local = fn (Carbon $at) => Carbon::createFromFormat('Y-m-d H:i:s', $at->format('Y-m-d H:i:s'), $tz);
                    [$worked, $overtime, $base] = $service->closedDayHours($a->user, $tenant, $a->shift?->forDate($a->work_date), $date, $tz,
                        $local($a->clock_in_at), $local($a->clock_out_at), (int) ($a->break_minutes ?? 0), $settings);

                    $new = ['worked_hours' => $worked, 'overtime_hours' => $overtime];
                    if (in_array($a->status, ['present', 'late', 'half_day'], true)) {
                        $new['status'] = AttendanceService::closedDayStatus((int) $a->late_minutes > 0, $worked, $base);
                    }
                    $reviewed = in_array($a->overtime_status, [Attendance::OVERTIME_APPROVED, Attendance::OVERTIME_REJECTED], true);
                    $overtimeChanged = abs((float) $a->overtime_hours - $overtime) >= 0.01;
                    if ($overtimeChanged && ! $reviewed) {
                        $new += ['overtime_status' => Attendance::overtimeStatusFor($overtime), 'overtime_approved_hours' => null];
                    }
                    if ($overtimeChanged && $reviewed) {
                        unset($new['overtime_hours']); // ya revisadas por RR. HH.: no se cambian solas
                        $review++;
                    }

                    $statusChanged = isset($new['status']) && $new['status'] !== $a->status;
                    if (abs((float) $a->worked_hours - $worked) < 0.01 && ! $overtimeChanged && ! $statusChanged) {
                        continue;
                    }
                    $changed++;
                    $rows[] = [$date, $a->user_id, (float) $a->worked_hours . ' → ' . $worked, (float) $a->overtime_hours . ' → ' . ($reviewed && $overtimeChanged ? "{$overtime} (ya revisada: no se cambia)" : $overtime), $a->status . ' → ' . ($new['status'] ?? $a->status)];
                    if ($apply) {
                        $a->update($new);
                    }
                }
            });

        $this->table(['Fecha', 'Colaborador (id)', 'Horas', 'Extra', 'Estado'], $rows);
        $this->line("{$tenant->slug} · {$from} a {$to}: {$changed} jornada(s) distintas" . ($review ? ", {$review} con extras ya revisadas (no se cambian)" : '') . '.');
        if ($apply && $changed) {
            $audit->log('attendance.recalculated', $tenant, ['from' => $from, 'to' => $to, 'changed' => $changed, 'operator' => get_current_user()]);
            $this->info('Guardado y registrado en la bitácora.');
        } elseif ($changed) {
            $this->comment('Solo vista previa: agrega --apply para guardar.');
        }

        return self::SUCCESS;
    }
}
