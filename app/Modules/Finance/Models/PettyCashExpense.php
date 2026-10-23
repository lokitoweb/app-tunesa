<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Gasto de caja chica con recibo correlativo por empresa (Fcaja_chica) */
class PettyCashExpense extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const DOCUMENT_TYPES = ['BOLETA', 'FACTURA', 'RECIBO', 'TICKET', 'SIN COMPROBANTE'];
    public const CASH_BOXES = ['CAJA EFECTIVO', 'CAJA PASAJES', 'CAJA ENCOMIENDAS', 'CAJA TOURS'];

    protected $fillable = [
        'tenant_id', 'receipt_number', 'date', 'description', 'category', 'beneficiary', 'document_type', 'document_number',
        'ruc', 'amount', 'cash_box', 'voucher_path', 'created_by',
    ];

    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];
}
