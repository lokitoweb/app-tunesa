<?php

namespace App\Modules\Attendance\Notifications;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AppNotification;

/**
 * attendance.incomplete_punches (QA3-04 · 4 marcaciones): el colaborador cerró su jornada sin
 * marcar la salida o el retorno del refrigerio en un horario de 4 marcaciones. Va al panel de
 * RR. HH., administración y el supervisor de la sucursal (in-app, withoutPush()).
 */
class AttendanceIncompletePunches extends AppNotification
{
    public function __construct(
        public readonly Attendance $attendance,
        public readonly ?User $employee = null,
    ) {}

    public function type(): string
    {
        return 'attendance.incomplete_punches';
    }

    public function title(): string
    {
        return 'Marcaciones incompletas';
    }

    public function body(): string
    {
        $missing = $this->attendance->break_start_at === null ? 'la salida y el retorno del refrigerio' : 'el retorno del refrigerio';

        return sprintf('%s cerró su jornada del %s sin marcar %s.',
            $this->employee?->full_name ?? 'Un colaborador', $this->attendance->work_date?->format('d/m/Y'), $missing);
    }

    public function payload(): array
    {
        return [
            'attendance_id' => $this->attendance->id,
            'user_id'       => $this->attendance->user_id,
            'work_date'     => $this->attendance->work_date?->toDateString(),
            'screen'        => 'team_attendance',
        ];
    }
}
