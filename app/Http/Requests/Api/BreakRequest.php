<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\MarksWithTenantSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * BreakRequest — Valida el registro de inicio o fin de descanso.
 * No requiere foto, pero sí GPS. Pasa por los mismos controles anti-fraude
 * que la entrada (GPS simulado y precisión máxima de "Marca y app").
 */
class BreakRequest extends FormRequest
{
    use MarksWithTenantSettings;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'latitude'        => ['required', 'numeric', 'between:-90,90'],
            'longitude'       => ['required', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['sometimes', 'numeric', 'min:0', 'max:10000'],
            'device_id'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'device_os'       => ['sometimes', 'nullable', 'string', 'in:android,ios'],
            'is_mocked'       => ['sometimes', 'boolean'],
            'app_version'     => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'latitude.required'  => 'La ubicación GPS es obligatoria.',
            'longitude.required' => 'La ubicación GPS es obligatoria.',
        ];
    }

    /** Metadatos del dispositivo (precisión, GPS simulado, versión) para la auditoría */
    public function deviceMeta(): array
    {
        return $this->baseDeviceMeta();
    }
}
