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
        return ['photo' => $this->photoRules()] + $this->offlineRules();
    }

    public function messages(): array
    {
        return [
            'photo.required' => 'La foto selfie es obligatoria para registrar salida.',
        ] + $this->offlineMessages();
    }
}
