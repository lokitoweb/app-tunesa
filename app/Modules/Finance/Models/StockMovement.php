<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Movimiento de kardex valorizado por costo promedio ponderado, por producto y almacén
 * (el almacén es la sede). Cada fila guarda el saldo resultante: el stock actual es el
 * saldo del último movimiento. Solo se agrega; no se edita ni se borra (traza).
 */
class StockMovement extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'product_id', 'branch_id', 'date', 'type', 'quantity', 'unit_cost', 'total_cost',
        'balance_quantity', 'balance_unit_cost', 'balance_total', 'requisition_id', 'vehicle_id', 'reference', 'notes', 'user_id',
    ];

    protected $casts = [
        'date' => 'date', 'quantity' => 'decimal:3', 'unit_cost' => 'decimal:4', 'total_cost' => 'decimal:2',
        'balance_quantity' => 'decimal:3', 'balance_unit_cost' => 'decimal:4', 'balance_total' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Registra una entrada o salida y recalcula el saldo. Entrada: el costo promedio se
     * pondera con el costo de la entrada. Salida: sale al costo promedio vigente y no puede
     * dejar el stock en negativo.
     *
     * @param  array{product_id:int, branch_id:int, type:string, quantity:float|string, unit_cost?:float|string|null}  $data
     */
    public static function record(array $data): self
    {
        return DB::transaction(function () use ($data) {
            $last = static::where(['product_id' => $data['product_id'], 'branch_id' => $data['branch_id']])
                ->lockForUpdate()->latest('id')->first();
            $qty = round((float) $data['quantity'], 3);
            $balanceQty = (float) ($last?->balance_quantity ?? 0);
            $balanceTotal = (float) ($last?->balance_total ?? 0);
            $avg = (float) ($last?->balance_unit_cost ?? 0);

            if ($data['type'] === 'in') {
                $unit = round((float) ($data['unit_cost'] ?? 0), 4);
                $total = round($qty * $unit, 2);
                $balanceQty = round($balanceQty + $qty, 3);
                $balanceTotal = round($balanceTotal + $total, 2);
                $avg = $balanceQty > 0 ? round($balanceTotal / $balanceQty, 4) : 0;
            } else {
                if ($qty > $balanceQty + 0.0005) {
                    throw ValidationException::withMessages(['quantity' => "Stock insuficiente: hay {$balanceQty} en el almacén."]);
                }
                $unit = $avg;
                $total = round($qty * $unit, 2);
                $balanceQty = round($balanceQty - $qty, 3);
                // La última unidad se lleva el saldo exacto: sin centavos colgados
                $balanceTotal = $balanceQty <= 0 ? 0 : round($balanceTotal - $total, 2);
                $total = $balanceQty <= 0 ? round((float) ($last?->balance_total ?? 0), 2) : $total;
            }

            // array_merge: los valores calculados pisan lo que venga en $data (p. ej. unit_cost null en una salida)
            return static::create(array_merge($data, [
                'date' => $data['date'] ?? now(tenant_timezone())->toDateString(), 'quantity' => $qty, 'unit_cost' => $unit, 'total_cost' => $total,
                'balance_quantity' => $balanceQty, 'balance_unit_cost' => $avg, 'balance_total' => $balanceTotal,
            ]));
        });
    }
}
