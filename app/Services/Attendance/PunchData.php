<?php

namespace App\Services\Attendance;

use App\Models\AttendancePunch;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Datos de una marcación tal como llegan del teléfono (endpoint individual o
 * un elemento de sync-batch), ya validados.
 *
 * Horas (todas normalizadas a UTC):
 *  - deviceTime:        reloj del teléfono en el momento de la marcación.
 *  - offlineRecordedAt: hora real de una marcación hecha sin conexión.
 *  - deviceSentAt:      reloj del teléfono al enviar (sync-batch). Sirve para
 *                       medir el desfase de reloj de una marcación offline.
 */
final class PunchData
{
    public function __construct(
        public readonly string $type,
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly ?float $accuracyMeters = null,
        public readonly bool $isMocked = false,
        public readonly ?bool $faceDetected = null,
        public readonly ?UploadedFile $photo = null,
        public readonly ?string $deviceId = null,
        public readonly ?string $deviceOs = null,
        public readonly ?string $appVersion = null,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
        public readonly ?CarbonImmutable $deviceTime = null,
        public readonly ?CarbonImmutable $offlineRecordedAt = null,
        public readonly ?CarbonImmutable $deviceSentAt = null,
        public readonly ?string $clientUuid = null,
    ) {
        if (! in_array($type, AttendancePunch::TYPES, true)) {
            throw new InvalidArgumentException("Tipo de marcación desconocido: {$type}");
        }
    }

    /**
     * @param  array<string, mixed>  $input  Campos ya validados (latitude, longitude, accuracy_meters, ...)
     */
    public static function fromInput(string $type, array $input, ?UploadedFile $photo = null, ?string $ip = null, ?string $userAgent = null, ?string $deviceSentAt = null): self
    {
        $bool = fn (string $key) => array_key_exists($key, $input) && $input[$key] !== null && $input[$key] !== ''
            ? filter_var($input[$key], FILTER_VALIDATE_BOOLEAN)
            : null;

        return new self(
            type: $type,
            latitude: (float) $input['latitude'],
            longitude: (float) $input['longitude'],
            accuracyMeters: isset($input['accuracy_meters']) && $input['accuracy_meters'] !== '' ? (float) $input['accuracy_meters'] : null,
            isMocked: (bool) $bool('is_mocked'),
            faceDetected: $bool('face_detected'),
            photo: $photo,
            deviceId: filled($input['device_id'] ?? null) ? (string) $input['device_id'] : null,
            deviceOs: filled($input['device_os'] ?? null) ? (string) $input['device_os'] : null,
            appVersion: filled($input['app_version'] ?? null) ? (string) $input['app_version'] : null,
            ip: $ip,
            userAgent: $userAgent,
            deviceTime: self::utc($input['device_time'] ?? null),
            offlineRecordedAt: self::utc($input['offline_recorded_at'] ?? null),
            deviceSentAt: self::utc($deviceSentAt ?? ($input['device_sent_at'] ?? null)),
            clientUuid: filled($input['client_uuid'] ?? null) ? strtolower((string) $input['client_uuid']) : null,
        );
    }

    public static function utc(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->utc();
    }

    public function isOffline(): bool
    {
        return $this->offlineRecordedAt !== null;
    }

    public function isClockEvent(): bool
    {
        return $this->type === 'clock_in' || $this->type === 'clock_out';
    }

    /** Metadatos para clock_in_meta / clock_out_meta (compatibles con lo guardado antes) */
    public function meta(): array
    {
        $meta = array_filter([
            'accuracy_meters' => $this->accuracyMeters,
            'device_os'       => $this->deviceOs,
            'app_version'     => $this->appVersion,
            'is_mocked'       => $this->isMocked ?: null,
            'ip'              => $this->ip,
            'user_agent'      => $this->userAgent,
            'device_time'     => $this->deviceTime?->toIso8601String(),
            'client_uuid'     => $this->clientUuid,
        ], fn ($v) => $v !== null && $v !== '');

        if ($this->faceDetected !== null) {
            $meta['face_detected'] = $this->faceDetected;
        }

        return $meta;
    }
}
