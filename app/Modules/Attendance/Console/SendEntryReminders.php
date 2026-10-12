<?php

namespace App\Modules\Attendance\Console;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Notifications\EntryReminder;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Services\TenantContext;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Holiday;
use App\Modules\HR\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Recordatorio push de entrada.
 *
 * Cada 5 minutos: a quien tenía turno hoy, ya pasó su hora de entrada más la
 * tolerancia (hasta WINDOW_MINUTES después) y no marcó, le llega un push.
 * Sin feriados ni licencias aprobadas; un solo aviso por persona y día.
 * Solo a colaboradores con teléfono registrado (fcm_token).
 *
 *   php artisan attendance:remind-entry
 */
class SendEntryReminders extends Command
{
    /** Minutos después de la tolerancia en los que todavía se recuerda */
    public const WINDOW_MINUTES = 60;

    protected $signature = 'attendance:remind-entry {--tenant= : Slug de una empresa concreta}';

    protected $description = 'Envía un push a quien empezó su turno y no marcó entrada';

    public function handle(TenantContext $context): int
    {
        $tenants = Tenant::where('status', 'active')
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        $sent = 0;
        foreach ($tenants as $tenant) {
            $context->set($tenant);
            try {
                $sent += $this->processTenant(Carbon::now($tenant->timezone ?: config('app.timezone')));
            } catch (\Throwable $e) {
                Log::warning("Recordatorio de entrada ({$tenant->slug}): " . $e->getMessage());
            }
        }
        $context->clear();

        $this->info("Recordatorios enviados: {$sent}.");

        return self::SUCCESS;
    }

    private function processTenant(Carbon $now): int
    {
        $day = $now->toDateString();

        if (Holiday::where('date', $day)->exists()) {
            return 0;
        }

        $due = EmployeeShift::current($day)
            ->with(['shift', 'user'])
            ->whereHas('user', fn ($q) => $q->where('status', 'active')->whereNotNull('fcm_token'))
            ->get()
            ->filter(function (EmployeeShift $a) use ($now, $day) {
                if (! $a->shift?->start_time || ! $a->worksOn($now)) {
                    return false;
                }
                $late = Carbon::parse("{$day} {$a->shift->start_time}", $now->getTimezone())
                    ->addMinutes((int) $a->shift->grace_period_minutes);

                return $now->gte($late) && $now->lt($late->copy()->addMinutes(self::WINDOW_MINUTES));
            })
            ->unique('user_id');

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
            if (! Cache::add("entry-reminder:{$user->id}:{$day}", true, now()->addDay())) {
                continue;
            }
            $user->notify(new EntryReminder($assignment->shift, $day));
            $sent++;
        }

        return $sent;
    }
}
