<?php

namespace App\Modules\Attendance\Notifications;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AppNotification;

/**
 * attendance.early_exit (QA Ronda 2 · regla vigente 7): el colaborador marcó su salida antes
 * de la salida oficial del horario del día. Va al panel del supervisor de la sucursal
 * (in-app, withoutPush()); la jornada queda con el estado "Salida anticipada".
 */
class AttendanceEarlyExit extends AppNotification
{
    public function __construct(
        public readonly Attendance $attendance,
        public readonly int $minutes,
        public readonly string $scheduledEnd,
        public readonly ?User $employee = null,
    ) {}

    public function type(): string
    {
        return 'attendance.early_exit';
    }

    public function title(): string
    {
        return 'Salida anticipada';
    }

    public function body(): string
    {
        return sprintf('%s marcó su salida a las %s, %d min antes de su salida oficial (%s).',
            $this->employee?->full_name ?? 'Un colaborador', hora($this->attendance->clock_out_at), $this->minutes, $this->scheduledEnd);
    }

    public function payload(): array
    {
        return [
            'attendance_id' => $this->attendance->id,
            'user_id'       => $this->attendance->user_id,
            'work_date'     => $this->attendance->work_date?->toDateString(),
            'minutes'       => $this->minutes,
            'screen'        => 'team_attendance',
        ];
    }
}
