<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Costo fijo mensual o cuota programada (costofijo) */
class FixedCost extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'supplier_id', 'period', 'classification', 'supplier_name', 'bank', 'account_number', 'detail', 'date',
        'amount', 'currency', 'installment', 'frequency', 'batch', 'detraction_amount', 'detraction_percent',
        'detraction_number', 'is_active',
    ];

    protected $casts = [
        'date'              => 'date',
        'amount'            => 'decimal:2',
        'detraction_amount' => 'decimal:2',
        'is_active'         => 'boolean',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
