<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cuota por pagar de un requerimiento (tblcuota_doc) */
class PaymentInstallment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'requisition_id', 'installment', 'due_date', 'amount', 'paid_amount', 'status', 'payment_method',
        'scheduled_for', 'scheduled_by', 'authorized_by_id', 'authorized_at', 'paid_at',
        'operation_number', 'registered_by', 'vouchers',
    ];

    protected $casts = [
        'due_date' => 'date', 'scheduled_for' => 'date', 'authorized_at' => 'datetime', 'paid_at' => 'datetime',
        'amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'vouchers' => 'array',
    ];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function cancellations(): HasMany
    {
        return $this->hasMany(PaymentCancellation::class);
    }
}
