<?php

namespace App\Modules\Attendance\Notifications;

use App\Modules\Core\Notifications\AppNotification;
use App\Modules\HR\Models\Shift;

/**
 * Al colaborador: su turno empieza en unos minutos (o ya empezó) y no marcó entrada.
 * El momento lo configura la empresa (antes o después de la hora de entrada).
 * Solo push (no llena la bandeja): lo envía attendance:remind-entry.
 */
class EntryReminder extends AppNotification
{
    public function __construct(
        public readonly Shift $shift,
        public readonly string $workDate,
        public readonly bool $beforeStart = false,
        public readonly int $minutesToStart = 5,
    ) {}

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
        if (! $this->beforeStart) {
            return '¿Ya marcaste tu entrada?';
        }
        $minutes = max(1, $this->minutesToStart);

        return $minutes === 1 ? 'Tu turno empieza en 1 minuto' : "Tu turno empieza en {$minutes} minutos";
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
