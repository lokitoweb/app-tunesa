<?php

namespace App\Modules\HR\Models;

use App\Modules\Core\Models\User;
use App\Modules\Core\Models\Concerns\BelongsToBranchScope;
use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\Concerns\HasDateOnlyColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrega de una prenda de dotación (historial).
 */
class UniformDelivery extends Model
{
    use BelongsToTenant, HasDateOnlyColumns, BelongsToBranchScope;

    /** Alcance por sede: [columna de sede, columna de colaborador] (BelongsToBranchScope) */
    public const BRANCH_SCOPE = [null, 'user_id'];

    protected array $dateOnly = ['delivered_at'];

    public const ITEMS = [
        'shirt'  => 'Polo / camisa',
        'pants'  => 'Pantalón',
        'shoes'  => 'Calzado',
        'jacket' => 'Casaca',
        'helmet' => 'Casco',
        'vest'   => 'Chaleco',
        'other'  => 'Otro',
    ];

    protected $fillable = ['tenant_id', 'user_id', 'item', 'size', 'quantity', 'delivered_at', 'delivered_by', 'notes'];

    protected $casts = [
        'delivered_at' => 'date',
        'quantity'     => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deliverer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function toApi(): array
    {
        return [
            'id'           => $this->id,
            'item'         => $this->item,
            'item_label'   => self::ITEMS[$this->item] ?? $this->item,
            'size'         => $this->size,
            'quantity'     => $this->quantity,
            'delivered_at' => $this->delivered_at?->toDateString(),
            'notes'        => $this->notes,
        ];
    }
}
