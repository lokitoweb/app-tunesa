<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Área operativa de la empresa (Operaciones, Caja, Almacén, Seguridad...).
 * Es transversal a las sedes: el colaborador tiene un área y una o más sedes.
 * Su jefe de área es el jefe inmediato de quienes trabajan en ella.
 */
class Department extends Model
{
    use BelongsToTenant;

    /** Áreas sugeridas al crear la primera vez (empresas de transporte y logística) */
    public const SUGGESTED = [
        'Operaciones / Ruta', 'Atención al cliente / Caja', 'Almacén y estiba', 'Seguridad',
        'Mantenimiento', 'Limpieza', 'Administración',
    ];

    protected $fillable = ['tenant_id', 'name', 'code', 'head_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Jefe de área: revisa las solicitudes (vacaciones, permisos) de su área */
    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_id');
    }
}
