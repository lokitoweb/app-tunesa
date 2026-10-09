<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Flags de la empresa sobre qué ve y edita el colaborador en la app.
 * Usar CompanyConfiguration::current() para obtener la de la empresa actual
 * (la crea con los valores por defecto si aún no existe).
 */
class CompanyConfiguration extends Model
{
    use BelongsToTenant;

    /** flag => [etiqueta, descripción] — orden del panel de control */
    public const FLAGS = [
        'mobile_payslips_enabled'     => ['Boletas de pago', 'El colaborador ve y descarga sus boletas publicadas.'],
        'mobile_documents_enabled'    => ['Mi legajo', 'El colaborador ve el estado de sus documentos.'],
        'mobile_documents_upload'     => ['Subir documentos', 'Puede subir DNI, CV y comprobante de domicilio desde el celular.'],
        'mobile_payment_info_enabled' => ['Datos de pago y previsión', 'Ve su cuenta bancaria y régimen previsional (enmascarados).'],
        'mobile_payment_info_edit'    => ['Editar cuenta bancaria', 'Puede registrar o cambiar su cuenta y AFP/ONP. RRHH lo verifica después.'],
        'mobile_uniforms_enabled'     => ['Dotación', 'Ve sus tallas y el historial de entregas de uniforme.'],
        'mobile_uniforms_edit'        => ['Editar tallas', 'Puede elegir sus tallas de polo, pantalón y calzado.'],
        'lock_verified_payment_info'  => ['Bloquear datos verificados', 'Cuando RRHH verifica la cuenta, el colaborador ya no puede cambiarla desde la app.'],
    ];

    public const DEFAULT_REQUIRED_DOCUMENTS = ['dni', 'cv', 'proof_of_address'];

    protected $fillable = [
        'tenant_id',
        'mobile_payslips_enabled', 'mobile_documents_enabled', 'mobile_documents_upload',
        'mobile_payment_info_enabled', 'mobile_payment_info_edit',
        'mobile_uniforms_enabled', 'mobile_uniforms_edit',
        'lock_verified_payment_info', 'required_documents', 'max_upload_mb', 'updated_by',
    ];

    protected $casts = [
        'mobile_payslips_enabled'     => 'boolean',
        'mobile_documents_enabled'    => 'boolean',
        'mobile_documents_upload'     => 'boolean',
        'mobile_payment_info_enabled' => 'boolean',
        'mobile_payment_info_edit'    => 'boolean',
        'mobile_uniforms_enabled'     => 'boolean',
        'mobile_uniforms_edit'        => 'boolean',
        'lock_verified_payment_info'  => 'boolean',
        'required_documents'          => 'array',
        'max_upload_mb'               => 'integer',
    ];

    protected $attributes = [
        'mobile_payslips_enabled'     => true,
        'mobile_documents_enabled'    => true,
        'mobile_documents_upload'     => true,
        'mobile_payment_info_enabled' => true,
        'mobile_payment_info_edit'    => true,
        'mobile_uniforms_enabled'     => true,
        'mobile_uniforms_edit'        => true,
        'lock_verified_payment_info'  => true,
        'max_upload_mb'               => 5,
    ];

    public static function current(): self
    {
        return static::firstOrCreate(['tenant_id' => current_tenant_id()]);
    }

    /** Configuración de una empresa concreta (p. ej. el legajo abierto desde la vista global) */
    public static function forTenantId(int $tenantId): self
    {
        return static::withoutGlobalScopes()->firstOrCreate(['tenant_id' => $tenantId]);
    }

    public function requiredDocuments(): array
    {
        return $this->required_documents ?: self::DEFAULT_REQUIRED_DOCUMENTS;
    }

    /** Un flag "editar" solo vale si su módulo está visible */
    public function allows(string $flag): bool
    {
        $parent = [
            'mobile_documents_upload'  => 'mobile_documents_enabled',
            'mobile_payment_info_edit' => 'mobile_payment_info_enabled',
            'mobile_uniforms_edit'     => 'mobile_uniforms_enabled',
        ][$flag] ?? null;

        return (bool) $this->{$flag} && ($parent === null || (bool) $this->{$parent});
    }

    /** Lo que recibe la app para el renderizado condicional */
    public function toApi(): array
    {
        return [
            'modules' => [
                'payslips'     => $this->allows('mobile_payslips_enabled'),
                'documents'    => $this->allows('mobile_documents_enabled'),
                'payment_info' => $this->allows('mobile_payment_info_enabled'),
                'uniforms'     => $this->allows('mobile_uniforms_enabled'),
            ],
            'permissions' => [
                'upload_documents'  => $this->allows('mobile_documents_upload'),
                'edit_payment_info' => $this->allows('mobile_payment_info_edit'),
                'edit_uniforms'     => $this->allows('mobile_uniforms_edit'),
            ],
            'required_documents' => $this->requiredDocuments(),
            'max_upload_mb'      => $this->max_upload_mb,
        ];
    }
}
