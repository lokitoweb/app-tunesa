<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cuota mensual de un costo fijo; las cuotas de un mismo registro comparten batch (nlote) */
class FixedCost extends Model
{
    use BelongsToTenant;

    /** Clasificaciones del ERP de escritorio (Frmcostos) */
    public const CLASSIFICATIONS = ['ARRENDAMIENTO', 'LEASING', 'SEGUROS - SOAT', 'PRESTAMOS', 'ASESORIA', 'SERVICIOS', 'OTROS'];

    protected $fillable = [
        'tenant_id', 'supplier_id', 'period', 'classification', 'supplier_name', 'bank', 'account_number', 'detail', 'date',
        'amount', 'currency', 'installment', 'frequency', 'batch', 'detraction_amount', 'detraction_percent',
        'detraction_number', 'is_active', 'paid_at', 'operation_number', 'company_bank_account_id',
    ];

    protected $casts = [
        'date'              => 'date',
        'paid_at'           => 'date',
        'amount'            => 'decimal:2',
        'detraction_amount' => 'decimal:2',
        'is_active'         => 'boolean',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function companyAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyBankAccount::class, 'company_bank_account_id');
    }
}
