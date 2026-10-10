<?php

namespace App\Services;

use App\Models\Attendance;

/**
 * Evidencia de una marcación para el visor de auditoría (panel y API):
 * selfies con URL temporal, hora exacta, sucursal, punto GPS y la
 * comparación de la distancia contra el radio permitido de la geocerca.
 */
class AttendanceEvidence
{
    public function __construct(private readonly SecureFileUrl $urls) {}

    public function photoUrl(Attendance $attendance, string $moment): ?string
    {
        $path = $moment === 'in' ? $attendance->clock_in_photo : $attendance->clock_out_photo;

        return $path
            ? $this->urls->make(config('filesystems.attendance_disk', 'private'), $path, "selfie-{$moment}", $attendance->id, 15)
            : null;
    }

    public function detail(Attendance $attendance): array
    {
        $branch = $attendance->branch;
        $radius = (int) ($branch->allowed_radius_meters ?? 0);

        $point = function (string $moment) use ($attendance, $radius) {
            $at = $attendance->{"clock_{$moment}_at"};
            if (! $at) {
                return null;
            }

            $distance = $attendance->{"clock_{$moment}_distance_meters"};
            $meta     = $attendance->{"clock_{$moment}_meta"} ?? [];

            return [
                'at'              => $at->toIso8601String(),
                'time'            => $at->format('H:i:s'),
                'latitude'        => $attendance->{"clock_{$moment}_latitude"} !== null ? (float) $attendance->{"clock_{$moment}_latitude"} : null,
                'longitude'       => $attendance->{"clock_{$moment}_longitude"} !== null ? (float) $attendance->{"clock_{$moment}_longitude"} : null,
                'distance_meters' => $distance,
                'within_geofence' => (bool) $attendance->{"clock_{$moment}_within_geofence"},
                // % del radio consumido (100 = justo en el borde)
                'radius_usage'    => $radius > 0 && $distance !== null ? (int) round(100 * $distance / $radius) : null,
                'excess_meters'   => $radius > 0 && $distance !== null ? max(0, $distance - $radius) : null,
                'gps_accuracy'    => $attendance->{"clock_{$moment}_accuracy_meters"} ?? $meta['accuracy_meters'] ?? null,
                'is_mocked'       => (bool) ($attendance->{"clock_{$moment}_mock_location"} || ($meta['is_mocked'] ?? false)),
                'face_detected'   => $attendance->{"clock_{$moment}_face_detected"},
                'device_time'     => $attendance->{"clock_{$moment}_device_time"}?->toIso8601String(),
                'time_drift_seconds' => $attendance->{"clock_{$moment}_time_drift_seconds"},
                'suspicion_reasons'  => $meta['suspicion_reasons'] ?? [],
                'suspicion_labels'   => array_map(fn ($r) => \App\Support\Labels::enum('suspicion_reason', $r), $meta['suspicion_reasons'] ?? []),
                'device'          => $attendance->{"clock_{$moment}_device_id"},
                'photo_url'       => $this->photoUrl($attendance, $moment),
            ];
        };

        return [
            'id'        => $attendance->id,
            'work_date' => $attendance->work_date?->toDateString(),
            'work_date_label' => $attendance->work_date?->format('d/m/Y'),
            'status'    => $attendance->status,
            'late_minutes'  => $attendance->late_minutes,
            'worked_hours'  => $attendance->worked_hours,
            'employee'  => [
                'id'   => $attendance->user?->id,
                'name' => $attendance->user?->full_name,
                'code' => $attendance->user?->employee_code,
            ],
            'branch'    => $branch ? [
                'id'        => $branch->id,
                'name'      => $branch->name,
                'latitude'  => (float) $branch->latitude,
                'longitude' => (float) $branch->longitude,
                'radius'    => $radius,
            ] : null,
            'shift'     => $attendance->shift?->name,
            'clock_in'  => $point('in'),
            'clock_out' => $point('out'),
            'is_suspicious' => (bool) $attendance->is_suspicious,
            // Bitácora inmutable: cada evento con su hora de servidor/teléfono, GPS y motivos
            'punches'   => $attendance->punches()->withoutGlobalScopes()->get()->map->toApi()->all(),
        ];
    }
}
