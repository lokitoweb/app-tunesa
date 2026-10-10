<?php

namespace App\Notifications;

use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\User;

/**
 * attendance.outside_schedule: el colaborador marcó entrada o salida fuera de
 * la ventana de su turno (± tolerancia) o en un día sin turno. La marcación se
 * aceptó, con motivo OUTSIDE_SCHEDULE. Va al colaborador (in-app + push) y al
 * supervisor de la sucursal (in-app, withoutPush()).
 */
class AttendanceOutsideSchedule extends AppNotification
{
    public function __construct(
        public readonly Attendance $attendance,
        public readonly AttendancePunch $punch,
        public readonly ?User $employee = null,
        public readonly bool $forSupervisor = false,
    ) {}

    public function type(): string
    {
        return 'attendance.outside_schedule';
    }

    public function title(): string
    {
        return 'Marcación fuera de horario';
    }

    public function body(): string
    {
        $moment = $this->punch->type === 'clock_out' ? 'Salida' : 'Entrada';
        $time   = $this->punch->type === 'clock_out' ? $this->attendance->clock_out_at : $this->attendance->clock_in_at;
        $shift  = $this->attendance->shift;
        $window = $shift ? sprintf(' (turno %s–%s)', substr((string) $shift->start_time, 0, 5), substr((string) $shift->end_time, 0, 5)) : '';

        if ($this->forSupervisor) {
            return sprintf('%s marcó %s a las %s, fuera de su horario%s.',
                $this->employee?->full_name ?? 'Un colaborador', mb_strtolower($moment), $time?->format('H:i'), $window);
        }

        return sprintf('%s registrada a las %s, fuera de tu horario%s. RRHH la revisará.', $moment, $time?->format('H:i'), $window);
    }

    public function payload(): array
    {
        return [
            'attendance_id' => $this->attendance->id,
            'punch_id'      => $this->punch->id,
            'punch_type'    => $this->punch->type,
            'user_id'       => $this->attendance->user_id,
            'work_date'     => $this->attendance->work_date?->toDateString(),
            'screen'        => $this->forSupervisor ? 'team_attendance' : 'attendance',
        ];
    }
}
