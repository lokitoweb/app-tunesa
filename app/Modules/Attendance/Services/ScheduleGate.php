<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\OffShiftAuthorization;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\EmployeeShift;
use Carbon\Carbon;

/**
 * ¿Puede la persona marcar su entrada en una fecha?
 *
 * Con `punch_requires_schedule` (por defecto sí) solo se marca un día con
 * turno programado: una asignación vigente cuyo turno trabaja esa fecha (fijo
 * por días de la semana o rotativo). Un día de descanso o sin programación se
 * bloquea, salvo que RRHH o Gerencia lo haya autorizado en el panel
 * (OffShiftAuthorization). La app y la API usan esta misma respuesta.
 */
class ScheduleGate
{
    public const MESSAGE_NOT_SCHEDULED = 'Hoy no tienes turno programado. Si debes trabajar, pide a RR. HH. que autorice tu marcación.';

    /**
     * @return array{required: bool, scheduled: bool, authorized: bool, can_clock_in: bool, reason: ?string, message: ?string, shift: ?array}
     */
    public function statusFor(User $user, string $date, ?TenantSetting $settings = null): array
    {
        $settings ??= TenantSetting::forTenant($user->tenant);
        $required = (bool) $settings->punch_requires_schedule;

        $assignments = EmployeeShift::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->current($date)
            ->with('shift')
            ->get();
        $day = Carbon::parse($date);
        $resolved = app(ScheduleResolver::class)->resolve($assignments, $day, ScheduleResolver::LEGACY_FIRST_WORKING,
            userId: $user->id, tenantId: $user->tenant_id);
        $working = $resolved->works ? $resolved->assignment : null;

        $scheduled = $resolved->works;
        $authorized = ! $scheduled && OffShiftAuthorization::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $date)
            ->exists();

        $canClockIn = ! $required || $scheduled || $authorized;
        $reason = $scheduled ? null : ($resolved->hasAssignments ? 'rest_day' : 'unscheduled');

        return [
            'required'     => $required,
            'scheduled'    => $scheduled,
            'authorized'   => $authorized,
            'can_clock_in' => $canClockIn,
            'reason'       => $reason,
            'message'      => $canClockIn ? null : self::MESSAGE_NOT_SCHEDULED,
            'shift'        => $working?->shift ? [
                'id'         => $working->shift->id,
                'name'       => $working->shift->name,
                'start_time' => substr((string) $working->shift->forDate($day)->start_time, 0, 5),
                'end_time'   => substr((string) $working->shift->forDate($day)->end_time, 0, 5),
            ] : null,
        ];
    }
}
