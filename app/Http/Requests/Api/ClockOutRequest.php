<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\MarksWithTenantSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ClockOutRequest — Valida la marcación de salida.
 * Igual que ClockIn (selfie según selfie_required de la empresa).
 */
class ClockOutRequest extends FormRequest
{
    use MarksWithTenantSettings;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'latitude'        => ['required', 'numeric', 'between:-90,90'],
            'longitude'       => ['required', 'numeric', 'between:-180,180'],
            'photo'           => $this->photoRules(),
            'accuracy_meters' => ['sometimes', 'numeric', 'min:0'],
            'device_id'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'device_os'       => ['sometimes', 'nullable', 'string', 'in:android,ios'],
            'is_mocked'       => ['sometimes', 'boolean'],
            'app_version'     => ['sometimes', 'nullable', 'string', 'max:20'],
        ] + $this->offlineRules();
    }

    public function messages(): array
    {
        return [
            'latitude.required'  => 'La latitud GPS es obligatoria.',
            'longitude.required' => 'La longitud GPS es obligatoria.',
            'photo.required'     => 'La foto selfie es obligatoria para registrar salida.',
            'photo.max'          => 'La foto no debe superar 5MB.',
        ] + $this->offlineMessages();
    }

    public function deviceMeta(): array
    {
        return $this->baseDeviceMeta();
    }
}

