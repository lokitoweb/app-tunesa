<?php

namespace App\Modules\Attendance\Notifications;

use App\Modules\Core\Notifications\AppNotification;
use App\Modules\HR\Models\Shift;

/**
 * Al colaborador: su turno empieza en 5 minutos (o ya empezó) y no marcó entrada.
 * Solo push (no llena la bandeja): lo envía attendance:remind-entry.
 */
class EntryReminder extends AppNotification
{
    public function __construct(public readonly Shift $shift, public readonly string $workDate, public readonly bool $beforeStart = false) {}

    protected function channels(object $notifiable): array
    {
        return [];
    }

    public function type(): string
    {
        return 'attendance.entry_reminder';
    }

    public function title(): string
    {
        return $this->beforeStart ? 'Tu turno empieza en 5 minutos' : '¿Ya marcaste tu entrada?';
    }

    public function body(): string
    {
        if ($this->beforeStart) {
            return sprintf('Tu turno empieza a las %s. Recuerda marcar tu entrada desde la app.', substr((string) $this->shift->start_time, 0, 5));
        }

        return sprintf('Tu turno empezó a las %s y aún no registras tu entrada. Márcala desde la app.', substr((string) $this->shift->start_time, 0, 5));
    }

    public function payload(): array
    {
        return ['work_date' => $this->workDate, 'screen' => 'home'];
    }
}
