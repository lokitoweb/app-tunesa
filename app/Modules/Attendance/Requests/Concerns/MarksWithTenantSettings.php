<?php

namespace App\Modules\Attendance\Requests\Concerns;

use App\Modules\Core\Models\TenantSetting;
use App\Modules\Attendance\Services\Attendance\PunchData;
use App\Modules\Core\Services\TenantContext;

/**
 * Reglas de marcación que dependen de "Marca y app" de la empresa (selfie
 * obligatoria u opcional) y campos de integridad comunes a entrada, descansos,
 * salida y a cada elemento de sync-batch.
 */
trait MarksWithTenantSettings
{
    private ?TenantSetting $effectiveSettings = null;

    public function tenantSettings(): TenantSetting
    {
        return $this->effectiveSettings ??= TenantSetting::forTenant(app(TenantContext::class)->getOrNull());
    }

    /** Selfie obligatoria solo si la empresa la exige (selfie_required) */
    protected function photoRules(): array
    {
        return self::photoRulesFor($this->tenantSettings()->selfie_required);
    }

    public static function photoRulesFor(bool $required): array
    {
        return [$required ? 'required' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];
    }

    protected function offlineRules(): array
    {
        return self::integrityRules();
    }

    /**
     * Campos comunes de integridad (endpoints individuales y sync-batch).
     * Tolerancia de 2 min hacia el futuro en offline_recorded_at por el reloj del celular.
     */
    public static function integrityRules(): array
    {
        return [
            'latitude'            => ['required', 'numeric', 'between:-90,90'],
            'longitude'           => ['required', 'numeric', 'between:-180,180'],
            'accuracy_meters'     => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'device_id'           => ['sometimes', 'nullable', 'string', 'max:255'],
            'device_os'           => ['sometimes', 'nullable', 'string', 'in:android,ios'],
            'is_mocked'           => ['sometimes', 'boolean'],
            'app_version'         => ['sometimes', 'nullable', 'string', 'max:40'],
            'offline_recorded_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:' . now()->addMinutes(2)->toIso8601String()],
            'face_detected'       => ['sometimes', 'nullable', 'boolean'],
            'device_time'         => ['sometimes', 'nullable', 'date'],
            'device_sent_at'      => ['sometimes', 'nullable', 'date'],
            'client_uuid'         => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    public static function integrityMessages(): array
    {
        return [
            'latitude.required'                   => 'La latitud GPS es obligatoria.',
            'latitude.between'                    => 'La latitud debe estar entre -90 y 90.',
            'longitude.required'                  => 'La longitud GPS es obligatoria.',
            'longitude.between'                   => 'La longitud debe estar entre -180 y 180.',
            'offline_recorded_at.date'            => 'La hora de la marcación sin conexión no es válida.',
            'offline_recorded_at.before_or_equal' => 'La hora de la marcación sin conexión no puede estar en el futuro.',
            'device_time.date'                    => 'La hora del dispositivo debe ser ISO 8601 (con zona horaria).',
            'client_uuid.uuid'                    => 'client_uuid debe ser un UUID.',
            'photo.image'                         => 'El archivo debe ser una imagen.',
            'photo.mimes'                         => 'La foto debe ser JPG, JPEG, PNG o WebP.',
            'photo.max'                           => 'La foto no debe superar 5MB.',
        ];
    }

    protected function offlineMessages(): array
    {
        return self::integrityMessages();
    }

    /** Datos de la marcación para AttendanceService::punch() */
    public function toPunchData(string $type): PunchData
    {
        return PunchData::fromInput(
            $type,
            $this->validated(),
            $this->file('photo'),
            $this->ip(),
            $this->userAgent(),
        );
    }
}
