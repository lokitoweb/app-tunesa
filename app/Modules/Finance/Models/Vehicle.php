<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Vehículo de la empresa (Tbus del ERP de escritorio): placa única por empresa */
class Vehicle extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const TYPES = ['BUS', 'CAMIONETA', 'AUTO', 'OTRO'];

    protected $fillable = ['tenant_id', 'plate', 'brand', 'model', 'type', 'branch_id', 'is_active', 'soat_expires_at', 'technical_review_expires_at'];

    protected $casts = ['is_active' => 'boolean', 'soat_expires_at' => 'date', 'technical_review_expires_at' => 'date'];

    /** Placa normalizada: mayúsculas, sin espacios (ABC-123) */
    public static function normalizePlate(?string $plate): ?string
    {
        $plate = strtoupper(preg_replace('/\s+/', '', (string) $plate));

        return $plate === '' ? null : $plate;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function fuelLoads(): HasMany
    {
        return $this->hasMany(FuelLoad::class);
    }
}
