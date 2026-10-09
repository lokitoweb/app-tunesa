<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ClockOutRequest — Valida la marcación de salida.
 * Igual que ClockIn pero también requiere foto.
 */
class ClockOutRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'latitude'        => ['required', 'numeric', 'between:-90,90'],
            'longitude'       => ['required', 'numeric', 'between:-180,180'],
            'photo'           => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'accuracy_meters' => ['sometimes', 'numeric', 'min:0'],
            'device_id'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'device_os'       => ['sometimes', 'nullable', 'string', 'in:android,ios'],
            'app_version'     => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'latitude.required'  => 'La latitud GPS es obligatoria.',
            'longitude.required' => 'La longitud GPS es obligatoria.',
            'photo.required'     => 'La foto selfie es obligatoria para registrar salida.',
            'photo.max'          => 'La foto no debe superar 5MB.',
        ];
    }

    public function deviceMeta(): array
    {
        return array_filter([
            'accuracy_meters' => $this->input('accuracy_meters'),
            'device_os'       => $this->input('device_os'),
            'app_version'     => $this->input('app_version'),
            'ip'              => $this->ip(),
            'user_agent'      => $this->userAgent(),
        ]);
    }
}

