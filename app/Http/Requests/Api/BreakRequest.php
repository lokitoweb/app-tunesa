<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * BreakRequest — Valida el registro de inicio o fin de descanso.
 * No requiere foto, pero sí GPS.
 */
class BreakRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'latitude'        => ['required', 'numeric', 'between:-90,90'],
            'longitude'       => ['required', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['sometimes', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'latitude.required'  => 'La ubicación GPS es obligatoria.',
            'longitude.required' => 'La ubicación GPS es obligatoria.',
        ];
    }
}
