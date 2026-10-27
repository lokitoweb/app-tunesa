<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cuota por pagar de un requerimiento (tblcuota_doc) */
class PaymentInstallment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'requisition_id', 'installment', 'due_date', 'amount', 'paid_amount', 'status', 'payment_method',
        'scheduled_for', 'scheduled_by', 'authorized_by_id', 'authorized_at', 'paid_at',
        'operation_number', 'registered_by', 'vouchers', 'is_advance', 'advance_applied_to_id',
    ];

    protected $casts = [
        'due_date' => 'date', 'scheduled_for' => 'date', 'authorized_at' => 'datetime', 'paid_at' => 'datetime',
        'amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'vouchers' => 'array', 'is_advance' => 'boolean',
    ];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function cancellations(): HasMany
    {
        return $this->hasMany(PaymentCancellation::class);
    }

    /**
     * Visto bueno de la persona designada (apoderado con "autoriza pagos"), desde el panel o
     * la app: una sola regla para ambos. La OP necesita su OC/OS y estar programada.
     */
    public function authorizeBy(User $user): void
    {
        abort_unless($this->status === 'pending', 422, 'La cuota ya está pagada.');
        abort_unless(Signatory::canAuthorize($user), 403, 'Solo la persona designada por la empresa autoriza pagos.');
        abort_unless($this->requisition?->orderNumber(), 422, 'La orden de pago necesita una OC/OS válida.');
        abort_unless($this->scheduled_for, 422, 'Programa la cuota antes de autorizarla.');
        abort_if($this->authorized_at, 422, 'La cuota ya está autorizada.');

        $this->update(['authorized_by_id' => $user->id, 'authorized_at' => now()]);
    }
}
