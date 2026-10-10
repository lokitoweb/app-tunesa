<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\MarksWithTenantSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * BreakRequest — Valida el inicio o fin de descanso.
 * GPS obligatorio; foto siempre opcional; acepta offline_recorded_at,
 * device_time y client_uuid como la entrada y la salida.
 */
class BreakRequest extends FormRequest
{
    use MarksWithTenantSettings;

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['photo' => self::photoRulesFor(false)] + $this->offlineRules();
    }

    public function messages(): array
    {
        return [
            'latitude.required'  => 'La ubicación GPS es obligatoria.',
            'longitude.required' => 'La ubicación GPS es obligatoria.',
        ] + $this->offlineMessages();
    }
}
