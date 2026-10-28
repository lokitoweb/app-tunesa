<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\SecureFileUrl;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Evidencia de una marcación para el visor de auditoría (panel y API):
 * selfies con URL temporal, hora exacta, sucursal, punto GPS y la
 * comparación de la distancia contra el radio permitido de la geocerca.
 */
class AttendanceEvidence
{
    /** Una vista de las selfies de una jornada se audita como máximo una vez por hora y persona */
    public const VIEW_AUDIT_MINUTES = 60;

    /** Integridad de la selfie guardada contra su huella SHA-256 */
    public const PHOTO_OK = 'ok';

    public const PHOTO_ALTERED = 'altered';

    public const PHOTO_MISSING = 'missing';

    public function __construct(
        private readonly SecureFileUrl $urls,
        private readonly AuditLogger $audit,
    ) {}

    /** Miniatura de 160 px para listados (null si no hay foto) */
    public function thumbUrl(Attendance $attendance, string $moment): ?string
    {
        $path = $moment === 'in' ? $attendance->clock_in_photo : $attendance->clock_out_photo;

        return $path ? $this->urls->thumb("selfie-{$moment}", $attendance->id, 15) : null;
    }

    /**
     * Audita (attendance.photo_viewed) que alguien abrió las selfies de la
     * jornada de otra persona. Las miniaturas de los listados no cuentan.
     */
    public function recordView(Attendance $attendance): void
    {
        $viewer = auth()->user();
        $hasPhotos = $attendance->clock_in_photo || $attendance->clock_out_photo
            || $attendance->punches()->withoutGlobalScopes()->whereNotNull('photo_path')->whereNull('photo_purged_at')->exists();

        if (! $viewer || ! $hasPhotos || (int) $viewer->id === (int) $attendance->user_id) {
            return;
        }

        if (Cache::add("attendance-photo-viewed:{$viewer->id}:{$attendance->id}", true, now()->addMinutes(self::VIEW_AUDIT_MINUTES))) {
            $this->audit->log('attendance.photo_viewed', $attendance, [
                'employee_id' => $attendance->user_id,
                'work_date'   => $attendance->work_date?->toDateString(),
            ]);
        }
    }

    /**
     * Compara la foto guardada con la huella que se tomó al recibirla.
     * null = no aplica (sin foto, sin huella porque es anterior a la Fase 2,
     * o borrada por retención). Si no coincide o falta el archivo se audita
     * (attendance.evidence_altered) una vez al día por marcación.
     */
    public function photoIntegrity(AttendancePunch $punch): ?string
    {
        if (! $punch->photo_path || ! $punch->photo_sha256 || $punch->photo_purged_at) {
            return null;
        }

        $disk = Storage::disk(config('filesystems.attendance_disk', 'private'));
        $stream = $disk->exists($punch->photo_path) ? $disk->readStream($punch->photo_path) : null;

        if (! $stream) {
            $state = self::PHOTO_MISSING;
        } else {
            $ctx = hash_init('sha256');
            hash_update_stream($ctx, $stream);
            fclose($stream);
            $state = hash_equals($punch->photo_sha256, hash_final($ctx)) ? self::PHOTO_OK : self::PHOTO_ALTERED;
        }

        if ($state !== self::PHOTO_OK && Cache::add("attendance-evidence-altered:{$punch->id}", true, now()->addDay())) {
            $this->audit->log('attendance.evidence_altered', $punch, [
                'attendance_id' => $punch->attendance_id,
                'employee_id'   => $punch->user_id,
                'problem'       => $state,
            ]);
        }

        return $state;
    }

    /**
     * Integridad de las fotos de varias marcaciones.
     *
     * @param  Collection<int, AttendancePunch>  $punches
     * @return array<int, string|null> id de marcación => estado
     */
    public function integrity(Collection $punches): array
    {
        return $punches->mapWithKeys(fn (AttendancePunch $p) => [$p->id => $this->photoIntegrity($p)])->all();
    }

    public function photoUrl(Attendance $attendance, string $moment): ?string
    {
        $path = $moment === 'in' ? $attendance->clock_in_photo : $attendance->clock_out_photo;

        return $path
            ? $this->urls->make(config('filesystems.attendance_disk', 'private'), $path, "selfie-{$moment}", $attendance->id, 15)
            : null;
    }

    public function detail(Attendance $attendance): array
    {
        $this->recordView($attendance);

        $branch = $attendance->branch;
        $punches = $attendance->punches()->withoutGlobalScopes()->orderBy('server_time')->orderBy('id')->get();
        $integrity = $this->integrity($punches);
        $radius = (int) ($branch->allowed_radius_meters ?? 0);

        $point = function (string $moment) use ($attendance, $radius, $punches, $integrity) {
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
                // null = sin ubicación (marca de contingencia desde Mi espacio)
                'within_geofence' => $attendance->{"clock_{$moment}_within_geofence"},
                'contingency'     => isset($meta['contingency_id']),
                // % del radio consumido (100 = justo en el borde)
                'radius_usage'    => $radius > 0 && $distance !== null ? (int) round(100 * $distance / $radius) : null,
                'excess_meters'   => $radius > 0 && $distance !== null ? max(0, $distance - $radius) : null,
                'gps_accuracy'    => $attendance->{"clock_{$moment}_accuracy_meters"} ?? $meta['accuracy_meters'] ?? null,
                'is_mocked'       => (bool) ($attendance->{"clock_{$moment}_mock_location"} || ($meta['is_mocked'] ?? false)),
                'face_detected'   => $attendance->{"clock_{$moment}_face_detected"},
                'device_time'     => $attendance->{"clock_{$moment}_device_time"}?->toIso8601String(),
                'time_drift_seconds' => $attendance->{"clock_{$moment}_time_drift_seconds"},
                'suspicion_reasons'  => $meta['suspicion_reasons'] ?? [],
                'suspicion_labels'   => array_map(fn ($r) => \App\Modules\Core\Support\Labels::enum('suspicion_reason', $r), $meta['suspicion_reasons'] ?? []),
                'device'          => $attendance->{"clock_{$moment}_device_id"},
                'device_flags'    => array_map(fn ($f) => \App\Modules\Core\Support\Labels::enum('device_flag', $f), $meta['device_flags'] ?? []),
                'photo_url'       => $this->photoUrl($attendance, $moment),
                // La selfie del resumen es la misma que la de su evento en la bitácora
                'photo_integrity' => ($punch = $punches->firstWhere('photo_path', $attendance->{"clock_{$moment}_photo"}))
                    && $attendance->{"clock_{$moment}_photo"} ? $integrity[$punch->id] : null,
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
            'punches'   => $punches->map(fn (AttendancePunch $p) => $p->toApi() + ['photo_integrity' => $integrity[$p->id]])->all(),
            'evidence_altered' => in_array(self::PHOTO_ALTERED, $integrity, true) || in_array(self::PHOTO_MISSING, $integrity, true),
        ];
    }
}
