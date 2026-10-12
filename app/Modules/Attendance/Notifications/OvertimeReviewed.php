<?php

namespace App\Modules\Attendance\Notifications;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Notifications\AppNotification;

/** Al colaborador: su jefatura aprobó o rechazó sus horas extra de un día */
class OvertimeReviewed extends AppNotification
{
    public function __construct(public readonly Attendance $attendance) {}

    public function type(): string
    {
        return 'overtime.' . $this->attendance->overtime_status; // overtime.approved | overtime.rejected
    }

    public function title(): string
    {
        return $this->approved() ? 'Horas extra aprobadas' : 'Horas extra rechazadas';
    }

    public function body(): string
    {
        $day = $this->attendance->work_date?->format('d/m/Y');
        $hours = rtrim(rtrim(number_format((float) $this->attendance->overtime_approved_hours, 2, '.', ''), '0'), '.');

        return $this->approved()
            ? "Te aprobaron {$hours} h extra del {$day}."
            : "Tus horas extra del {$day} no fueron aprobadas.";
    }

    public function payload(): array
    {
        return [
            'attendance_id'           => $this->attendance->id,
            'work_date'               => $this->attendance->work_date?->toDateString(),
            'overtime_status'         => $this->attendance->overtime_status,
            'overtime_approved_hours' => $this->approved() ? (float) $this->attendance->overtime_approved_hours : null,
            'screen'                  => 'attendance',
        ];
    }

    private function approved(): bool
    {
        return $this->attendance->overtime_status === Attendance::OVERTIME_APPROVED;
    }
}
