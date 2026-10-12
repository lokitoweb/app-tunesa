<?php

namespace App\Modules\Attendance\Notifications;

use App\Modules\Core\Notifications\AppNotification;
use Carbon\CarbonInterface;

/**
 * Al colaborador: su jornada ya terminó y no marcó salida.
 * Solo push (no llena la bandeja): lo envía attendance:remind-entry.
 */
class ExitReminder extends AppNotification
{
    public function __construct(public readonly CarbonInterface $expectedEnd, public readonly string $workDate) {}

    protected function channels(object $notifiable): array
    {
        return [];
    }

    public function type(): string
    {
        return 'attendance.exit_reminder';
    }

    public function title(): string
    {
        return '¿Ya marcaste tu salida?';
    }

    public function body(): string
    {
        return sprintf('Tu jornada terminó a las %s y aún no registras tu salida. Márcala desde la app.', $this->expectedEnd->format('H:i'));
    }

    public function payload(): array
    {
        return ['work_date' => $this->workDate, 'screen' => 'home'];
    }
}
