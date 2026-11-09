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
 *  - Entrada: los minutos antes o después de la hora de entrada del turno que
 *    configure la empresa (Marca y app › Reglas de asistencia; por defecto 5
 *    minutos después), y hasta WINDOW_MINUTES más la tolerancia por si el
 *    programador se atrasó, a quien tiene turno ese día y no marcó. Sin
 *    feriados ni licencias aprobadas.
 *  - Salida: los minutos antes o después de la hora de salida esperada que configure la
 *    empresa (por defecto 5 minutos después; la hora es la del turno,
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
    /** Minutos durante los que todavía se recuerda si el aviso no salió a tiempo */
    public const WINDOW_MINUTES = 60;

    protected $signature = 'attendance:remind-entry {--tenant= : Slug de una empresa concreta}';

    protected $description = 'Push a quien no marcó entrada o salida (minutos antes o después, configurables por empresa)';

    /** Marca de "ya se le recordó la entrada ese día" (guarda la hora del envío; push:diagnose la lee) */
    public static function entryKey(int $userId, string $day): string
    {
        return "entry-reminder:{$userId}:{$day}";
    }

    /**
     * ¿Una licencia aprobada de ese día cubre la hora de entrada? Vacaciones, licencia médica o
     * permiso de día completo (sin horas): sí. Permiso por horas: solo si incluye la hora de
     * entrada (un permiso de 10:00 a 12:00 no exime de marcar a las 08:00).
     */
    public static function leaveCoversEntry(?\Illuminate\Support\Collection $leaves, string $entry): bool
    {
        $hm = fn ($t) => substr((string) $t, 0, 5);

        return (bool) $leaves?->contains(fn ($leave) => ! $leave->start_time || ! $leave->end_time
            || ($hm($leave->start_time) <= $hm($entry) && $hm($entry) < $hm($leave->end_time)));
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
                $settings = TenantSetting::forTenant($tenant);
                $sent += $this->entryReminders($now, $settings->reminderOffsetMinutes('entry'))
                    + $this->exitReminders($now, $settings->reminderOffsetMinutes('exit'), (int) $settings->lunch_minutes);
            } catch (\Throwable $e) {
                Log::warning("Recordatorio de entrada ({$tenant->slug}): " . $e->getMessage());
            }
        }
        $context->clear();

        $this->info("Recordatorios enviados: {$sent}.");

        return self::SUCCESS;
    }

    /** @param int $offset minutos respecto a la entrada: negativo = antes, positivo = después */
    private function entryReminders(Carbon $now, int $offset): int
    {
        // El aviso puede caer en otro día que el turno: 5 min antes de un turno de las 00:02 sale
        // a las 23:57 del día anterior; 5 min después de uno de las 23:58, a las 00:03 del siguiente
        $days = [$now->toDateString(), $now->copy()->subMinutes($offset)->toDateString()];

        $sent = 0;
        foreach (array_unique($days) as $day) {
            $sent += $this->entryRemindersFor($now, $day, $offset);
        }

        return $sent;
    }

    private function entryRemindersFor(Carbon $now, string $day, int $offset): int
    {
        if (Holiday::daysOff()->where('date', $day)->exists()) {
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
            ->map(fn ($mine, $userId) => $schedules->resolve($mine, $date, ScheduleResolver::LEGACY_FIRST_WORKING, function (EmployeeShift $a) use ($now, $day, $offset) {
                if (! $a->shift?->start_time) {
                    return false;
                }
                $start = Carbon::parse("{$day} {$a->shift->start_time}", $now->getTimezone());
                $from  = $start->copy()->addMinutes($offset);
                $until = $start->copy()->addMinutes(max(0, $offset) + (int) $a->shift->grace_period_minutes + self::WINDOW_MINUTES);

                return $now->gte($from) && $now->lt($until);
            }, userId: (int) $userId, tenantId: $tenantId))
            ->filter(fn (DaySchedule $d) => $d->works && $d->assignment->user?->status === 'active' && $d->assignment->user?->fcm_token)
            ->map(fn (DaySchedule $d) => $d->assignment)
            ->values();

        if ($due->isEmpty()) {
            return 0;
        }

        $userIds = $due->pluck('user_id');
        $marked  = Attendance::where('work_date', $day)->whereIn('user_id', $userIds)->pluck('user_id')->flip();
        $leaves  = LeaveRequest::where('status', 'approved')->overlapping($day, $day)->whereIn('user_id', $userIds)
            ->get(['user_id', 'start_time', 'end_time'])->groupBy('user_id');

        $sent = 0;
        foreach ($due as $assignment) {
            $user = $assignment->user;
            if (isset($marked[$user->id]) || self::leaveCoversEntry($leaves->get($user->id), (string) $assignment->shift->start_time)) {
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
                $user->notify(new EntryReminder($assignment->shift, $day, $now->lt($start), (int) ceil($now->diffInSeconds($start, false) / 60)));
                Log::info("Recordatorio de entrada enviado a {$user->email} (turno {$assignment->shift->start_time}, {$day})");
                $sent++;
            } catch (\Throwable $e) {
                Cache::forget($key);
                Log::warning("Recordatorio de entrada a {$user->email} falló: " . $e->getMessage());
            }
        }

        return $sent;
    }

    /** @param int $offset minutos respecto a la salida: negativo = antes, positivo = después */
    private function exitReminders(Carbon $now, int $offset, int $lunch): int
    {
        $tz = $now->getTimezone();
        $open = Attendance::query()
            ->whereNotNull('clock_in_at')->whereNull('clock_out_at')
            ->whereBetween('work_date', [$now->copy()->subDay()->toDateString(), $now->toDateString()])
            ->whereHas('user', fn ($q) => $q->where('status', 'active')->whereNotNull('fcm_token'))
            ->with(['shift', 'user'])
            ->get();


        $sent = 0;
        foreach ($open as $attendance) {
            $end = AttendanceService::expectedClockOut($attendance, $tz, $lunch);
            $from = $end->copy()->addMinutes($offset);
            if ($now->lt($from) || $now->gte($from->copy()->addMinutes(self::WINDOW_MINUTES))) {
                continue;
            }
            $key = "exit-reminder:{$attendance->id}";
            if (! Cache::add($key, $now->format('H:i'), now()->addDays(2))) {
                continue;
            }
            try {
                $attendance->user->notify(new ExitReminder($end, $attendance->work_date->toDateString(), $now->lt($end)));
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
