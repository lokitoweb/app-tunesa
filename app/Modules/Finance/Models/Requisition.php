<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Requerimiento de compra/servicio con su OC u OS (tblrequerimiento) */
class Requisition extends Model
{
    use BelongsToTenant, SoftDeletes;

    /** Flujo del ERP de escritorio: REQUERIMIENTO -> SOLICITUD (cotización) -> OC/OS */
    public const STAGES = [
        'requested' => ['Solicitado', 'warning'],
        'approved'  => ['Aprobado', 'info'],
        'quoting'   => ['En cotización', 'primary'],
        'ordered'   => ['Orden emitida', 'success'],
        'paid'      => ['Pagado', 'dark'],
        'rejected'  => ['Rechazado', 'danger'],
        'cancelled' => ['Anulado', 'secondary'],
    ];

    /** Conceptos de pago de RRHH (FrmRegrrhh): la CTS se deposita en la cuenta CTS del legajo */
    public const PAYROLL_CONCEPTS = [
        'HABERES'       => 'Pago de haberes',
        'GRATIFICACION' => 'Gratificación',
        'VACACIONES'    => 'Vacaciones',
        'CTS'           => 'Depósito CTS',
        'LIQUIDACION'   => 'Liquidación de beneficios sociales',
    ];

    /** Tope del Requerimiento Express (soles, exclusivo) */
    public const EXPRESS_LIMIT = 600;

    /** Etapas en las que aún se puede editar o anular */
    public const OPEN = ['requested', 'approved', 'quoting'];

    // Catálogos del ERP de escritorio (combos de FrmReq)
    public const CLASSIFICATIONS = [
        'MERCADERÍAS', 'PRODUCTOS TERMINADOS', 'MATERIAS PRIMAS', 'ENVASES', 'MATERIALES AUXILIARES',
        'SERVICIO DE MANTENIMIENTO', 'SERVICIO DE TRANSPORTE', 'SUMINISTROS', 'REPUESTOS', 'EMBALAJES',
        'SUBPRODUCTOS', 'DESECHOS Y DESPERDICIOS',
    ];
    public const CURRENCIES = ['PEN' => 'Soles', 'USD' => 'Dólares', 'EUR' => 'Euros'];
    public const PAYMENT_TERMS = ['CONTADO', 'CREDITO'];
    public const PAYMENT_METHODS = ['TRANSFERENCIA', 'EFECTIVO', 'DEPOSITO', 'YAPE', 'TARJETA'];
    public const PRIORITIES = ['NORMAL', 'INTERMEDIO', 'ALTO'];
    public const PAYMENT_ORIGINS = ['TRANSFERENCIA', 'CAJA EFECTIVO', 'CAJA PASAJES', 'CAJA ENCOMIENDAS', 'CAJA TOURS', 'OTROS'];

    protected $fillable = [
        'tenant_id', 'series', 'number', 'code', 'year', 'is_express', 'requested_at', 'detail', 'area', 'requested_by', 'user_id', 'email', 'plate', 'km',
        'agency', 'site', 'delivery_place', 'classification', 'stage', 'payment_terms', 'notes', 'supplier_id',
        'purchase_order_number', 'service_order_number', 'ordered_at', 'received_at', 'invoice_number', 'invoice_path', 'invoice_date', 'vehicle_id', 'employee_id', 'payroll_period', 'payroll_concept', 'payment_origin', 'sale_type', 'priority',
        'payment_method', 'reviewed_by', 'reviewed_by_id', 'reviewed_at', 'currency', 'amount', 'amount_payable', 'has_detraction',
        'detraction_number', 'detraction_amount', 'detraction_percent', 'detraction_reason',
        'installments', 'attachments',
        'prices_include_igv', 'igv_rate', 'taxable_amount', 'igv_amount', 'is_direct_payment', 'advance_amount',
        'quote_payment_terms', 'delivery_mode', 'quote_notes',
    ];

    protected $casts = [
        'requested_at'   => 'datetime',
        'reviewed_at'    => 'datetime',
        'ordered_at'     => 'datetime',
        'received_at'    => 'datetime',
        'invoice_date'   => 'date',
        'amount'         => 'decimal:2',
        'amount_payable' => 'decimal:2',
        'detraction_amount' => 'decimal:2',
        'has_detraction' => 'boolean',
        'is_express'     => 'boolean',
        'attachments'    => 'array',
        'prices_include_igv' => 'boolean',
        'igv_rate'       => 'decimal:4',
        'taxable_amount' => 'decimal:2',
        'igv_amount'     => 'decimal:2',
        'is_direct_payment' => 'boolean',
        'advance_amount' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** Colaborador beneficiario de un pago de RRHH */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function isPayroll(): bool
    {
        return (bool) $this->payroll_concept;
    }

    /**
     * Cuenta del legajo a la que se paga (la CTS para el concepto CTS). Solo vale si RRHH la
     * verificó: el colaborador puede editarla desde la app.
     *
     * @return array{bank: ?string, number: ?string, verified: bool}
     */
    public function payrollAccount(): array
    {
        $profile = $this->employee?->profile;
        $cts = $this->payroll_concept === 'CTS';

        return [
            'bank'     => $cts ? $profile?->cts_bank_name : $profile?->bank_name,
            'number'   => $cts ? $profile?->cts_account_number : $profile?->bank_account_number,
            'verified' => $profile?->payment_info_status === 'verified',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequisitionItem::class);
    }

    public function quoteRequests(): HasMany
    {
        return $this->hasMany(QuoteRequest::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    /** Cuotas por pagar ('installments' es la columna con cuántas son) */
    public function paymentInstallments(): HasMany
    {
        return $this->hasMany(PaymentInstallment::class)->orderBy('installment');
    }

    public function code(): string
    {
        // Formal (RQ-TN-SED-01-000001-2026) desde el Bloque 3; los migrados del ERP conservan RQ-000123
        return ($this->attributes['code'] ?? null) ?: sprintf('%s-%06d', $this->series ?: 'RQ', $this->number);
    }

    public function isOpen(): bool
    {
        return in_array($this->stage, self::OPEN, true);
    }

    public function orderNumber(): ?string
    {
        // Pago de RRHH (PL de la planilla) o pago directo de Tesorería (PG): su documento es su propio código
        return $this->purchase_order_number ?: $this->service_order_number ?: ($this->isPayroll() || $this->is_direct_payment ? $this->code() : null);
    }
}
