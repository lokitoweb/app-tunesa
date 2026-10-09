<?php

namespace App\Http\Requests\Api\Concerns;

use App\Models\TenantSetting;
use App\Services\TenantContext;

/**
 * Reglas de marcación que dependen de "Marca y app" de la empresa:
 * selfie obligatoria u opcional, hora de marcación sin conexión y detección de rostro.
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
        return [
            $this->tenantSettings()->selfie_required ? 'required' : 'nullable',
            'image',
            'mimes:jpg,jpeg,png,webp',
            'max:5120',
        ];
    }

    protected function offlineRules(): array
    {
        return [
            // Hora real en que se marcó sin conexión (ISO 8601). Tolerancia de 2 min por reloj del celular.
            'offline_recorded_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:' . now()->addMinutes(2)->toIso8601String()],
            'face_detected'       => ['sometimes', 'boolean'],
        ];
    }

    protected function offlineMessages(): array
    {
        return [
            'offline_recorded_at.date'            => 'La hora de la marcación sin conexión no es válida.',
            'offline_recorded_at.before_or_equal' => 'La hora de la marcación sin conexión no puede estar en el futuro.',
        ];
    }

    /** Metadatos comunes del dispositivo + face_detected si la app lo informó */
    protected function baseDeviceMeta(): array
    {
        $meta = array_filter([
            'accuracy_meters' => $this->input('accuracy_meters'),
            'device_os'       => $this->input('device_os'),
            'app_version'     => $this->input('app_version'),
            'is_mocked'       => $this->has('is_mocked') ? $this->boolean('is_mocked') : null,
            'ip'              => $this->ip(),
            'user_agent'      => $this->userAgent(),
        ]);

        if ($this->has('face_detected')) {
            $meta['face_detected'] = $this->boolean('face_detected');
        }

        return $meta;
    }
}
