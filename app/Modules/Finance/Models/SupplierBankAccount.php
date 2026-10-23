<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cuenta bancaria de un proveedor (tcuenta) */
class SupplierBankAccount extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'supplier_id', 'bank', 'account_number', 'account_type'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
