<?php

namespace App\Modules\Attendance\Console;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Notifications\EntryReminder;
use App\Modules\Attendance\Notifications\ExitReminder;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Services\DaySchedule;
use App\Modules\Attendance\Services\ScheduleResolver;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Services\TenantContext;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Holiday;
use App\Modules\HR\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Recordatorios push de entrada y de salida. Corre cada minuto.
 *
 *  - Entrada: LEAD_MINUTES antes de la hora de entrada del turno (y hasta
 *    WINDOW_MINUTES pasada la tolerancia, por si el programador se atrasó), a
 *    quien tiene turno ese día y no marcó. Sin feriados ni licencias aprobadas.
 *  - Salida: LEAD_MINUTES después de la hora de salida esperada (la del turno,
 *    o entrada + jornada base + descanso si no tiene turno), a quien marcó
 *    entrada y no salida. Turnos nocturnos: la salida cae al día siguiente.
 *
 * Un aviso de cada tipo por persona y jornada. Solo a colaboradores con
 * teléfono registrado (fcm_token).
 *
 *   php artisan attendance:remind-entry
 */
class SendEntryReminders extends Command
{
    /** Minutos antes de la entrada y después de la salida en que se avisa */
    public const LEAD_MINUTES = 5;

    /** Minutos durante los que todavía se recuerda si el aviso no salió a tiempo */
    public const WINDOW_MINUTES = 60;

    protected $signature = 'attendance:remind-entry {--tenant= : Slug de una empresa concreta}';

    protected $description = 'Push 5 min antes del turno a quien no marcó entrada y 5 min después a quien no marcó salida';

    /** Marca de "ya se le recordó la entrada ese día" (guarda la hora del envío; push:diagnose la lee) */
    public static function entryKey(int $userId, string $day): string
    {
        return "entry-reminder:{$userId}:{$day}";
    }

    public function handle(TenantContext $context): int
    {
        $tenants = Tenant::where('status', 'active')
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        $sent = 0;
        foreach ($tenants as $tenant) {
            $context->set($tenant);
            try {
                $now = Carbon::now($tenant->timezone ?: config('app.timezone'));
                $sent += $this->entryReminders($now) + $this->exitReminders($now);
            } catch (\Throwable $e) {
                Log::warning("Recordatorio de entrada ({$tenant->slug}): " . $e->getMessage());
            }
        }
        $context->clear();

        $this->info("Recordatorios enviados: {$sent}.");

        return self::SUCCESS;
    }

    private function entryReminders(Carbon $now): int
    {
        // Un turno que empieza a las 00:02 se avisa a las 23:57 del día anterior
        $days = [$now->toDateString(), $now->copy()->addMinutes(self::LEAD_MINUTES)->toDateString()];

        $sent = 0;
        foreach (array_unique($days) as $day) {
            $sent += $this->entryRemindersFor($now, $day);
        }

        return $sent;
    }

    private function entryRemindersFor(Carbon $now, string $day): int
    {
        if (Holiday::where('date', $day)->exists()) {
            return 0;
        }
        $date = Carbon::parse($day, $now->getTimezone());
        $schedules = app(ScheduleResolver::class);

        $tenantId = app(TenantContext::class)->id();
        $byUser = EmployeeShift::current($day)
            ->with(['shift', 'user'])
            ->whereHas('user', fn ($q) => $q->where('status', 'active')->whereNotNull('fcm_token'))
            ->get()
            // Horario propio de ese día de la semana (sábado corto, etc.)
            ->each(fn (EmployeeShift $a) => $a->setRelation('shift', $a->shift?->forDate($date)))
            ->groupBy('user_id');
        // schedule_v2: también quien solo tiene el día programado en el calendario
        foreach ($schedules->programmedUserIds($tenantId, $day) as $userId) {
            $byUser[$userId] ??= collect();
        }
        $schedules->preload($tenantId, $byUser->keys()->all(), $day, $day);

        $due = $byUser
            ->map(fn ($mine, $userId) => $schedules->resolve($mine, $date, ScheduleResolver::LEGACY_FIRST_WORKING, function (EmployeeShift $a) use ($now, $day) {
                if (! $a->shift?->start_time) {
                    return false;
                }
                $start = Carbon::parse("{$day} {$a->shift->start_time}", $now->getTimezone());
                $until = $start->copy()->addMinutes((int) $a->shift->grace_period_minutes + self::WINDOW_MINUTES);

                return $now->gte($start->copy()->subMinutes(self::LEAD_MINUTES)) && $now->lt($until);
            }, userId: (int) $userId, tenantId: $tenantId))
            ->filter(fn (DaySchedule $d) => $d->works && $d->assignment->user?->status === 'active' && $d->assignment->user?->fcm_token)
            ->map(fn (DaySchedule $d) => $d->assignment)
            ->values();

        if ($due->isEmpty()) {
            return 0;
        }

        $userIds = $due->pluck('user_id');
        $marked  = Attendance::where('work_date', $day)->whereIn('user_id', $userIds)->pluck('user_id')->flip();
        $onLeave = LeaveRequest::where('status', 'approved')->overlapping($day, $day)->whereIn('user_id', $userIds)->pluck('user_id')->flip();

        $sent = 0;
        foreach ($due as $assignment) {
            $user = $assignment->user;
            if (isset($marked[$user->id]) || isset($onLeave[$user->id])) {
                continue;
            }
            // Un aviso por persona y día aunque el comando corra varias veces
            $key = self::entryKey($user->id, $day);
            if (! Cache::add($key, $now->format('H:i'), now()->addDay())) {
                continue;
            }
            $start = Carbon::parse("{$day} {$assignment->shift->start_time}", $now->getTimezone());
            // Si el envío falla, se libera para reintentar en el minuto siguiente y no frena a los demás
            try {
                $user->notify(new EntryReminder($assignment->shift, $day, $now->lt($start)));
                Log::info("Recordatorio de entrada enviado a {$user->email} (turno {$assignment->shift->start_time}, {$day})");
                $sent++;
            } catch (\Throwable $e) {
                Cache::forget($key);
                Log::warning("Recordatorio de entrada a {$user->email} falló: " . $e->getMessage());
            }
        }

        return $sent;
    }

    private function exitReminders(Carbon $now): int
    {
        $tz = $now->getTimezone();
        $open = Attendance::query()
            ->whereNotNull('clock_in_at')->whereNull('clock_out_at')
            ->whereBetween('work_date', [$now->copy()->subDay()->toDateString(), $now->toDateString()])
            ->whereHas('user', fn ($q) => $q->where('status', 'active')->whereNotNull('fcm_token'))
            ->with(['shift', 'user'])
            ->get();

        $lunch = (int) TenantSetting::forTenant(app(TenantContext::class)->get())->lunch_minutes;

        $sent = 0;
        foreach ($open as $attendance) {
            $end = AttendanceService::expectedClockOut($attendance, $tz, $lunch);
            $from = $end->copy()->addMinutes(self::LEAD_MINUTES);
            if ($now->lt($from) || $now->gte($from->copy()->addMinutes(self::WINDOW_MINUTES))) {
                continue;
            }
            $key = "exit-reminder:{$attendance->id}";
            if (! Cache::add($key, $now->format('H:i'), now()->addDays(2))) {
                continue;
            }
            try {
                $attendance->user->notify(new ExitReminder($end, $attendance->work_date->toDateString()));
                Log::info("Recordatorio de salida enviado a {$attendance->user->email} ({$attendance->work_date->toDateString()})");
                $sent++;
            } catch (\Throwable $e) {
                Cache::forget($key);
                Log::warning("Recordatorio de salida a {$attendance->user->email} falló: " . $e->getMessage());
            }
        }

        return $sent;
    }
}
