<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cotización de un proveedor para un requerimiento */
class Quotation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'requisition_id', 'supplier_id', 'number', 'bank', 'account_number', 'approved_by', 'stage',
        'quoted_at', 'expires_at', 'contact', 'phone', 'amount', 'currency', 'notes', 'is_selected', 'file_path',
    ];

    protected $casts = ['quoted_at' => 'datetime', 'expires_at' => 'datetime', 'amount' => 'decimal:2', 'is_selected' => 'boolean'];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
