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

    protected $fillable = [
        'tenant_id', 'series', 'number', 'requested_at', 'detail', 'area', 'requested_by', 'user_id', 'email', 'plate', 'km',
        'agency', 'site', 'delivery_place', 'classification', 'stage', 'payment_terms', 'notes', 'supplier_id',
        'purchase_order_number', 'service_order_number', 'invoice_number', 'payment_origin', 'sale_type', 'priority',
        'payment_method', 'reviewed_by', 'currency', 'amount', 'amount_payable', 'has_detraction', 'installments', 'attachments',
    ];

    protected $casts = [
        'requested_at'   => 'datetime',
        'amount'         => 'decimal:2',
        'amount_payable' => 'decimal:2',
        'has_detraction' => 'boolean',
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
}
