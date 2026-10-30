<?php

namespace App\Modules\HR\Models;

use App\Modules\Core\Models\User;
use App\Modules\Core\Models\Concerns\BelongsToBranchScope;
use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\Concerns\HasDateOnlyColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Documento del legajo digital (DNI, CV, contrato, comprobante de domicilio...).
 * El archivo está en un disco privado; se entrega con SecureFileUrl.
 */
class EmployeeDocument extends Model
{
    use BelongsToTenant, HasDateOnlyColumns, SoftDeletes, BelongsToBranchScope;

    /** Alcance por sede: [columna de sede, columna de colaborador] (BelongsToBranchScope) */
    public const BRANCH_SCOPE = [null, 'user_id'];

    protected array $dateOnly = ['expires_at'];

    public const TYPES = [
        'dni'              => 'Documento de identidad',
        'cv'               => 'Currículum (CV)',
        'contract'         => 'Contrato',
        'proof_of_address' => 'Recibo de servicios / domicilio',
        'certificate'      => 'Certificado',
        'medical_exam'     => 'Examen médico ocupacional',
        'driver_license'   => 'Licencia de conducir',
        'pension_exemption' => 'Exoneración de aporte a pensiones',
        'other'            => 'Otro',
    ];

    /** Tipos que admiten varios archivos a la vez (certificados, otros): uno nuevo no reemplaza al anterior */
    public const MULTIPLE_TYPES = ['certificate', 'other'];

    /** Tipos que el colaborador puede subir desde la app (el contrato lo carga RRHH) */
    public const EMPLOYEE_TYPES = ['dni', 'cv', 'proof_of_address', 'certificate', 'medical_exam', 'driver_license', 'pension_exemption', 'other'];

    /**
     * Suelen vencer: si se suben sin fecha se avisa que no habrá alerta, pero se guardan igual (el
     * examen médico puede programarse después). Cualquier documento con fecha entra en las alertas.
     */
    public const EXPIRING_TYPES = ['medical_exam', 'driver_license'];

    public const STATUSES = [
        'pending'    => 'Por revisar',
        'approved'   => 'Aprobado',
        'rejected'   => 'Rechazado',
        'superseded' => 'Reemplazado',
    ];

    public const ALLOWED_MIMES = ['pdf', 'jpg', 'jpeg', 'png'];

    protected $fillable = [
        'tenant_id', 'user_id', 'uploaded_by', 'type', 'title', 'disk', 'path', 'original_name',
        'mime_type', 'size_bytes', 'sha256', 'status', 'rejection_reason', 'reviewed_by', 'reviewed_at', 'expires_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'expires_at'  => 'date',
        'size_bytes'  => 'integer',
    ];

    protected $hidden = ['path', 'disk'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function scopeCurrent($query)
    {
        return $query->whereIn('status', ['pending', 'approved']);
    }

    public function toApi(?string $url = null): array
    {
        return [
            'id'               => $this->id,
            'type'             => $this->type,
            'type_label'       => self::TYPES[$this->type] ?? $this->type,
            'title'            => $this->title,
            'original_name'    => $this->original_name,
            'mime_type'        => $this->mime_type,
            'size_kb'          => round($this->size_bytes / 1024, 1),
            'sha256'           => $this->sha256,
            'status'           => $this->status,
            'status_label'     => self::STATUSES[$this->status] ?? $this->status,
            'rejection_reason' => $this->rejection_reason,
            'expires_at'       => $this->expires_at?->toDateString(),
            'reviewed_at'      => $this->reviewed_at?->toIso8601String(),
            'uploaded_at'      => $this->created_at?->toIso8601String(),
            'url'              => $url,
        ];
    }
}
