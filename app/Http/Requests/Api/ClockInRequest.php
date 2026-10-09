<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\MarksWithTenantSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ClockInRequest — Valida los datos de marcación de entrada.
 *
 * La app móvil envía un multipart/form-data con:
 * - Coordenadas GPS del dispositivo
 * - Foto selfie capturada en el momento
 * - Metadatos del dispositivo
 */
class ClockInRequest extends FormRequest
{
    use MarksWithTenantSettings;

    public function authorize(): bool
    {
        return true; // La autorización se maneja en el middleware
    }

    public function rules(): array
    {
        return [
            // GPS
            'latitude'  => [
                'required',
                'numeric',
                'between:-90,90',
            ],
            'longitude' => [
                'required',
                'numeric',
                'between:-180,180',
            ],

            // Foto selfie (5 MB máx.): obligatoria salvo que la empresa la desactive (selfie_required)
            'photo' => $this->photoRules(),

            // Precisión del GPS en metros (del sensor del teléfono)
            'accuracy_meters' => ['sometimes', 'numeric', 'min:0', 'max:10000'],

            // ID único del dispositivo (para auditoría)
            'device_id' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Metadatos opcionales del dispositivo
            'device_os'      => ['sometimes', 'nullable', 'string', 'in:android,ios'],
            'is_mocked'       => ['sometimes', 'boolean'],
            'app_version'    => ['sometimes', 'nullable', 'string', 'max:20'],
        ] + $this->offlineRules();
    }

    public function messages(): array
    {
        return [
            'latitude.required'   => 'La latitud GPS es obligatoria.',
            'latitude.between'    => 'La latitud debe estar entre -90 y 90.',
            'longitude.required'  => 'La longitud GPS es obligatoria.',
            'longitude.between'   => 'La longitud debe estar entre -180 y 180.',
            'photo.required'      => 'La foto selfie es obligatoria para registrar entrada.',
            'photo.image'         => 'El archivo debe ser una imagen.',
            'photo.mimes'         => 'La foto debe ser JPG, JPEG, PNG o WebP.',
            'photo.max'           => 'La foto no debe superar 5MB.',
        ] + $this->offlineMessages();
    }

    /**
     * Construye el array de metadatos del dispositivo a partir del request.
     */
    public function deviceMeta(): array
    {
        return $this->baseDeviceMeta();
    }
}
