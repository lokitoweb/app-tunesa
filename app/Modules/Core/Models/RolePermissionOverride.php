<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Diferencia de una empresa con el rol estándar (ver la migración create_role_permission_overrides) */
class RolePermissionOverride extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'role', 'permission_id', 'granted', 'updated_by'];

    protected $casts = ['granted' => 'boolean'];

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }
}
