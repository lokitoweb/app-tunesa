<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Solicitud de cotización enviada a un proveedor (tsolcotizacion) */
class QuoteRequest extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'requisition_id', 'supplier_id', 'code', 'emailed_at', 'sent_by'];

    protected $casts = ['emailed_at' => 'datetime'];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
