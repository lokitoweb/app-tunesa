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
        'purchase_order_number', 'service_order_number', 'ordered_at', 'received_at', 'invoice_number', 'invoice_path', 'invoice_date', 'vehicle_id', 'payment_origin', 'sale_type', 'priority',
        'payment_method', 'reviewed_by', 'reviewed_by_id', 'reviewed_at', 'currency', 'amount', 'amount_payable', 'has_detraction',
        'detraction_number', 'detraction_amount', 'detraction_percent',
        'installments', 'attachments',
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
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
        return $this->purchase_order_number ?: $this->service_order_number;
    }
}
