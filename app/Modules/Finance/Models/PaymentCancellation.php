<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pago total o amortización de una cuota (tblcancelaciones) */
class PaymentCancellation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'payment_installment_id', 'company_bank_account_id', 'paid_at', 'amount', 'bank', 'account_number', 'operation_number', 'voucher_path',
        'is_amortization', 'registered_by', 'authorized_by',
    ];

    protected $casts = ['paid_at' => 'datetime', 'amount' => 'decimal:2', 'is_amortization' => 'boolean'];

    public function companyAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyBankAccount::class, 'company_bank_account_id');
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(PaymentInstallment::class, 'payment_installment_id');
    }
}
