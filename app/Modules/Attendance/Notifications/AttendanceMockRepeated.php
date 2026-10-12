<?php

namespace App\Modules\Attendance\Notifications;

use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AppNotification;

/**
 * attendance.mock_repeated: un colaborador intentó marcar con GPS falso
 * AttendanceAlertNotifier::MOCK_ATTEMPTS_ALERT veces en el día. Va al
 * supervisor de su sede y a administradores y RRHH (in-app, sin push).
 */
class AttendanceMockRepeated extends AppNotification
{
    public function __construct(
        public readonly User $employee,
        public readonly int $attempts,
        public readonly ?string $branchName = null,
    ) {}

    public function type(): string
    {
        return 'attendance.mock_repeated';
    }

    public function title(): string
    {
        return 'Intentos con GPS falso';
    }

    public function body(): string
    {
        return sprintf(
            '%s intentó marcar %d veces hoy con una ubicación simulada%s. Revisa sus intentos rechazados.',
            $this->employee->full_name,
            $this->attempts,
            $this->branchName ? " ({$this->branchName})" : '',
        );
    }

    public function payload(): array
    {
        return [
            'user_id'  => $this->employee->id,
            'attempts' => $this->attempts,
            'screen'   => 'attendance_attempts',
        ];
    }
}
