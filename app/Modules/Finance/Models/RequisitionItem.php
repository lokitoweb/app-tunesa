<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Línea de un requerimiento (tdetalle); la empresa la da el requerimiento */
class RequisitionItem extends Model
{
    protected $fillable = ['requisition_id', 'product_id', 'quantity', 'received_quantity', 'justification', 'unit_price', 'discount_percent', 'subtotal'];

    protected $casts = [
        'quantity' => 'decimal:3', 'received_quantity' => 'decimal:3',
        'unit_price' => 'decimal:4', 'discount_percent' => 'decimal:2', 'subtotal' => 'decimal:2',
    ];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
