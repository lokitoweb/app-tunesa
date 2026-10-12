<?php

namespace App\Modules\Onboarding\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Training\Models\TrainingCourse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Etapa de incorporación de una empresa. Las claves son fijas; la empresa
 * decide cuáles son obligatorias y qué curso es la inducción. Los documentos
 * exigidos salen de Configuración de la app (CompanyConfiguration::requiredDocuments).
 */
class OnboardingStage extends Model
{
    use BelongsToTenant;

    public const PERSONAL_DATA = 'personal_data';
    public const UNIFORMS      = 'uniforms';
    public const DOCUMENTS     = 'documents';
    public const INDUCTION     = 'induction';

    /** clave => [título, descripción para el colaborador] (en orden) */
    public const KEYS = [
        self::PERSONAL_DATA => ['Datos personales', 'Dirección, contacto de emergencia, cuenta de abono y régimen de pensiones.'],
        self::UNIFORMS      => ['Tallas de uniforme', 'Talla de camisa, pantalón y calzado.'],
        self::DOCUMENTS     => ['Documentos', 'Sube los documentos que pide RRHH a tu legajo.'],
        self::INDUCTION     => ['Inducción', 'Completa el curso de inducción y aprueba la evaluación.'],
    ];

    protected $fillable = ['tenant_id', 'key', 'position', 'is_required', 'course_id'];

    protected $casts = [
        'position'    => 'integer',
        'is_required' => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class, 'course_id');
    }

    public function title(): string
    {
        return self::KEYS[$this->key][0] ?? $this->key;
    }

    public function description(): string
    {
        return self::KEYS[$this->key][1] ?? '';
    }
}
