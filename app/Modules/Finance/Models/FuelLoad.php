<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Carga de combustible de un vehículo, con kilometraje para el rendimiento (km/galón) */
class FuelLoad extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const FUEL_TYPES = ['DIESEL', 'GASOHOL 90', 'GASOHOL 95', 'GLP', 'GNV'];

    protected $fillable = [
        'tenant_id', 'vehicle_id', 'date', 'gallons', 'price_per_gallon', 'total', 'odometer', 'fuel_type',
        'supplier_id', 'document_number', 'driver', 'requisition_id', 'user_id',
    ];

    protected $casts = ['date' => 'date', 'gallons' => 'decimal:3', 'price_per_gallon' => 'decimal:4', 'total' => 'decimal:2', 'odometer' => 'integer'];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
