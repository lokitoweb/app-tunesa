<?php

namespace App\Notifications;

use App\Models\LeaveRequest;

/** Al supervisor de la sucursal y a RRHH: un colaborador envió una solicitud */
class LeaveRequestSubmitted extends AppNotification
{
    public function __construct(public readonly LeaveRequest $leave) {}

    public function type(): string
    {
        return 'leave_request.submitted';
    }

    public function title(): string
    {
        return 'Nueva solicitud por aprobar';
    }

    public function body(): string
    {
        return sprintf(
            '%s solicitó %s del %s al %s.',
            $this->leave->employee?->full_name ?? 'Un colaborador',
            mb_strtolower($this->leave->type_label),
            $this->leave->start_date?->format('d/m/Y'),
            $this->leave->end_date?->format('d/m/Y')
        );
    }

    public function payload(): array
    {
        return [
            'leave_request_id' => $this->leave->id,
            'employee_id'      => $this->leave->user_id,
            'screen'           => 'approvals',
        ];
    }
}
