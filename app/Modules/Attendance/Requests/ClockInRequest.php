<?php

namespace App\Modules\Attendance\Requests;

use App\Modules\Attendance\Requests\Concerns\MarksWithTenantSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ClockInRequest — Valida la marcación de entrada (multipart/form-data):
 * GPS, selfie (obligatoria según selfie_required), metadatos del dispositivo
 * y campos de integridad (device_time, client_uuid, offline_recorded_at...).
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
        return ['photo' => $this->photoRules()] + $this->offlineRules();
    }

    public function messages(): array
    {
        return [
            'photo.required' => 'La foto selfie es obligatoria para registrar entrada.',
        ] + $this->offlineMessages();
    }
}
