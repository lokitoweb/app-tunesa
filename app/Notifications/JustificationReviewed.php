<?php

namespace App\Notifications;

use App\Models\Attendance;

/** Al colaborador: su justificación de asistencia fue aprobada o rechazada */
class JustificationReviewed extends AppNotification
{
    public function __construct(public readonly Attendance $attendance) {}

    public function type(): string
    {
        return 'justification.' . $this->attendance->justification_status; // justification.approved | justification.rejected
    }

    public function title(): string
    {
        return $this->approved() ? 'Justificación aprobada' : 'Justificación rechazada';
    }

    public function body(): string
    {
        $day = $this->attendance->work_date?->format('d/m/Y');

        return $this->approved()
            ? "Tu justificación del {$day} fue aprobada."
            : "Tu justificación del {$day} fue rechazada.";
    }

    public function payload(): array
    {
        return [
            'attendance_id'        => $this->attendance->id,
            'work_date'            => $this->attendance->work_date?->toDateString(),
            'justification_status' => $this->attendance->justification_status,
            'screen'               => 'attendance',
        ];
    }

    private function approved(): bool
    {
        return $this->attendance->justification_status === 'approved';
    }
}
