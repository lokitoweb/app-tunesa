<?php

namespace App\Modules\HR\Models;

use App\Modules\Core\Models\User;
use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ficha laboral del colaborador (datos de pago, previsión, régimen laboral, CTS).
 *
 * Las cuentas y el CUSPP se cifran en reposo con APP_KEY; en las respuestas
 * de la API se muestran enmascarados salvo en la exportación de planilla.
 */
class EmployeeProfile extends Model
{
    use BelongsToTenant;

    public const BANK_ACCOUNT_TYPES = ['savings' => 'Ahorros', 'checking' => 'Corriente', 'payroll' => 'Cuenta sueldo'];
    public const PENSION_SYSTEMS    = ['afp' => 'AFP (privado)', 'onp' => 'ONP (estatal)', 'social_security' => 'Seguridad Social', 'none' => 'Sin régimen'];
    public const HEALTH_INSURANCES  = ['essalud' => 'EsSalud', 'eps' => 'EPS', 'sis' => 'SIS', 'imss' => 'IMSS', 'private' => 'Seguro privado', 'none' => 'Ninguno'];
    public const LABOR_REGIMES      = [
        'general'       => 'Régimen general',
        'micro_company' => 'Microempresa',
        'small_company' => 'Pequeña empresa',
        'agrarian'      => 'Agrario',
        'construction'  => 'Construcción civil',
        'part_time'     => 'Tiempo parcial',
        'other'         => 'Otro',
    ];
    public const PAYMENT_STATUSES   = ['incomplete' => 'Incompleto', 'pending' => 'Por verificar', 'verified' => 'Verificado', 'rejected' => 'Observado'];

    /** Campos que el colaborador puede editar desde la app (si la empresa lo permite) */
    public const EMPLOYEE_PAYMENT_FIELDS = [
        'bank_name', 'bank_account_type', 'bank_account_currency', 'bank_account_number', 'interbank_code',
        'pension_system', 'pension_fund', 'pension_code', 'health_insurance',
    ];

    public const EMPLOYEE_CONTACT_FIELDS = ['address', 'district', 'emergency_contact_name', 'emergency_contact_phone'];

    protected $fillable = [
        'tenant_id', 'user_id',
        'address', 'district', 'emergency_contact_name', 'emergency_contact_phone',
        'bank_name', 'bank_account_type', 'bank_account_currency', 'bank_account_number', 'interbank_code',
        'pension_system', 'pension_fund', 'pension_code', 'health_insurance',
        'labor_regime', 'cts_bank_name', 'cts_account_number', 'cts_currency',
        'payment_info_status', 'payment_info_updated_at', 'payment_verified_by', 'payment_verified_at', 'payment_rejection_reason',
    ];

    protected $casts = [
        'bank_account_number'     => 'encrypted',
        'interbank_code'          => 'encrypted',
        'pension_code'            => 'encrypted',
        'cts_account_number'      => 'encrypted',
        'payment_info_updated_at' => 'datetime',
        'payment_verified_at'     => 'datetime',
    ];

    protected $attributes = [
        'payment_info_status' => 'incomplete',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_verified_by');
    }

    /** "1234567890" → "••••••7890" */
    public static function mask(?string $value, int $visible = 4): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $len = mb_strlen($value);

        return $len <= $visible ? $value : str_repeat('•', min(6, $len - $visible)) . mb_substr($value, -$visible);
    }

    /** La cuenta de abono y el régimen previsional están completos */
    public function hasCompletePaymentInfo(): bool
    {
        $pensionOk = $this->pension_system === 'none'
            || ($this->pension_system && ($this->pension_system !== 'afp' || $this->pension_fund));

        return $this->bank_name && $this->bank_account_number && $pensionOk;
    }

    /**
     * Representación para la app / API. $full = true solo para RRHH con
     * permiso de datos sensibles (y queda auditado por quien lo llama).
     */
    public function toApi(bool $full = false): array
    {
        $secret = fn (?string $v) => $full ? $v : self::mask($v);

        return [
            'contact' => [
                'address'                 => $this->address,
                'district'                => $this->district,
                'emergency_contact_name'  => $this->emergency_contact_name,
                'emergency_contact_phone' => $this->emergency_contact_phone,
            ],
            'payment' => [
                'bank_name'             => $this->bank_name,
                'bank_account_type'     => $this->bank_account_type,
                'bank_account_currency' => $this->bank_account_currency,
                'bank_account_number'   => $secret($this->bank_account_number),
                'interbank_code'        => $secret($this->interbank_code),
                'status'                => $this->payment_info_status,
                'status_label'          => self::PAYMENT_STATUSES[$this->payment_info_status] ?? $this->payment_info_status,
                'rejection_reason'      => $this->payment_rejection_reason,
                'updated_at'            => $this->payment_info_updated_at?->toIso8601String(),
                'verified_at'           => $this->payment_verified_at?->toIso8601String(),
            ],
            'pension' => [
                'pension_system'   => $this->pension_system,
                'pension_fund'     => $this->pension_fund,
                'pension_code'     => $secret($this->pension_code),
                'health_insurance' => $this->health_insurance,
            ],
            'employment' => [
                'labor_regime'       => $this->labor_regime,
                'labor_regime_label' => self::LABOR_REGIMES[$this->labor_regime] ?? null,
                'hire_date'          => $this->user?->hire_date?->toDateString(),
                'cts_bank_name'      => $this->cts_bank_name,
                'cts_account_number' => $secret($this->cts_account_number),
                'cts_currency'       => $this->cts_currency,
            ],
        ];
    }
}
