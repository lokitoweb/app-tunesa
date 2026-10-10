<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Macro-zona o región de la empresa (Zona Norte, Centro, Sur): agrupa sedes.
 */
class Zone extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'code', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }
}
