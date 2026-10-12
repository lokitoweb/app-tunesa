<?php

namespace App\Modules\Attendance\Notifications;

use App\Modules\Core\Notifications\AppNotification;
use App\Modules\HR\Models\Shift;

/**
 * Al colaborador: su turno ya empezó y no marcó entrada.
 * Solo push (no llena la bandeja): lo envía attendance:remind-entry.
 */
class EntryReminder extends AppNotification
{
    public function __construct(public readonly Shift $shift, public readonly string $workDate) {}

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
        return '¿Ya marcaste tu entrada?';
    }

    public function body(): string
    {
        return sprintf('Tu turno empezó a las %s y aún no registras tu entrada. Márcala desde la app.', substr((string) $this->shift->start_time, 0, 5));
    }

    public function payload(): array
    {
        return ['work_date' => $this->workDate, 'screen' => 'home'];
    }
}
