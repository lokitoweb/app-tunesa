<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Proveedor (tproveedor del ERP de escritorio) */
class Supplier extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['tenant_id', 'name', 'ruc', 'dni', 'address', 'phone', 'email', 'contact', 'city', 'category', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(SupplierBankAccount::class);
    }
}
