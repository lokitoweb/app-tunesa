<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Modelo Permission
 * Permiso atómico con formato "modulo.accion" (ej: shifts.manage).
 */
class Permission extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'group',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
