<?php

namespace App\Modules\HR\Notifications;

use App\Modules\Core\Notifications\AppNotification;
use App\Modules\HR\Models\EmployeeShift;

/** Al colaborador: le asignaron un turno nuevo o le cambiaron el horario */
class ShiftAssigned extends AppNotification
{
    public function __construct(public readonly EmployeeShift $assignment) {}

    public function type(): string
    {
        return 'shift.assigned';
    }

    public function title(): string
    {
        return 'Tienes un nuevo horario';
    }

    public function body(): string
    {
        $shift = $this->assignment->shift;
        $hours = $shift ? sprintf(' (%s a %s)', substr((string) $shift->start_time, 0, 5), substr((string) $shift->end_time, 0, 5)) : '';
        $from  = $this->assignment->effective_from;
        $since = $from && $from->isFuture() ? ' desde el ' . $from->format('d/m/Y') : '';

        return 'Te asignaron el turno ' . ($shift?->name ?? 'de trabajo') . $hours . $since . '.';
    }

    public function payload(): array
    {
        return [
            'employee_shift_id' => $this->assignment->id,
            'shift_id'          => $this->assignment->shift_id,
            'screen'            => 'home',
        ];
    }
}
