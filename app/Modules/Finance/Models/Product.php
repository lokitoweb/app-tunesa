<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Producto o servicio que se pide en un requerimiento (tproducto) */
class Product extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'classification', 'unit'];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RequisitionItem::class);
    }
}
