<?php

namespace App\Modules\Attendance\Notifications;

use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Models\User;

/**
 * attendance.outside_geofence: marcación de entrada o salida lejos de la
 * sucursal. Con política "reject" el intento se rechazó (GEOFENCE_VIOLATION);
 * con "flag" se aceptó con motivo OUT_OF_GEOFENCE. Va al colaborador (in-app +
 * push) y al supervisor de la sucursal (in-app, withoutPush()).
 */
class AttendanceOutsideGeofence extends AppNotification
{
    /**
     * @param  array{branch_id?: ?int, branch_name?: ?string, distance_meters?: ?int, allowed_radius?: ?int, attendance_id?: ?int, punch_id?: ?int}  $info
     */
    public function __construct(
        public readonly array $info,
        public readonly string $punchType,
        public readonly bool $rejected,
        public readonly ?User $employee = null,
        public readonly bool $forSupervisor = false,
    ) {}

    public function type(): string
    {
        return 'attendance.outside_geofence';
    }

    public function title(): string
    {
        return $this->rejected ? 'Marcación rechazada: fuera de la sucursal' : 'Marcación fuera de la sucursal';
    }

    public function body(): string
    {
        $moment   = $this->punchType === 'clock_out' ? 'salida' : 'entrada';
        $distance = $this->info['distance_meters'] ?? null;
        $radius   = $this->info['allowed_radius'] ?? null;
        $where    = $distance !== null
            ? sprintf(' a %d m de %s%s', $distance, $this->info['branch_name'] ?? 'la sucursal', $radius ? " (máx. {$radius} m)" : '')
            : '';

        if ($this->forSupervisor) {
            return sprintf('%s intentó marcar %s%s. %s',
                $this->employee?->full_name ?? 'Un colaborador', $moment, $where,
                $this->rejected ? 'La marcación fue rechazada.' : 'Quedó registrada para revisión.');
        }

        return $this->rejected
            ? "Tu {$moment} no se registró: estabas{$where}. Acércate a la sucursal e intenta de nuevo."
            : "Tu {$moment} se registró{$where}. RRHH la revisará.";
    }

    public function payload(): array
    {
        return array_filter([
            'attendance_id'   => $this->info['attendance_id'] ?? null,
            'punch_id'        => $this->info['punch_id'] ?? null,
            'punch_type'      => $this->punchType,
            'branch_id'       => $this->info['branch_id'] ?? null,
            'distance_meters' => $this->info['distance_meters'] ?? null,
            'allowed_radius'  => $this->info['allowed_radius'] ?? null,
            'user_id'         => $this->employee?->id,
            'rejected'        => $this->rejected,
            'screen'          => $this->forSupervisor ? 'team_attendance' : 'attendance',
        ], fn ($v) => $v !== null);
    }
}
