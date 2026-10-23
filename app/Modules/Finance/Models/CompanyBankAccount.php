<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Cuenta de la empresa desde la que se paga (TCuenta_origen) */
class CompanyBankAccount extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'bank', 'account_number', 'currency', 'alias', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function label(): string
    {
        return trim(($this->alias ? "{$this->alias} · " : '') . "{$this->bank} {$this->account_number} ({$this->currency})");
    }
}
