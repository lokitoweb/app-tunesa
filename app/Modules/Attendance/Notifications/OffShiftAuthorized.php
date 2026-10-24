<?php

namespace App\Modules\Attendance\Notifications;

use App\Modules\Attendance\Models\OffShiftAuthorization;
use App\Modules\Core\Notifications\AppNotification;

/** Push + bandeja: RRHH habilitó marcar un día sin turno programado */
class OffShiftAuthorized extends AppNotification
{
    public function __construct(public readonly OffShiftAuthorization $authorization) {}

    public function type(): string
    {
        return 'shift.offshift_authorized';
    }

    public function title(): string
    {
        return 'Puedes marcar asistencia';
    }

    public function body(): string
    {
        $date = $this->authorization->work_date;
        $when = $date->isToday() ? 'hoy' : 'el ' . $date->format('d/m/Y');

        return "RR. HH. autorizó tu marcación {$when}, aunque no tenías turno programado.";
    }

    public function payload(): array
    {
        return ['screen' => 'home', 'date' => $this->authorization->work_date->toDateString()];
    }
}
