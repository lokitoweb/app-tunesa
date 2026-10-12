<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cargo o puesto (Atención al cliente, Almacenero, Chofer, Estibador...).
 * Pertenece opcionalmente a un área y cada sede define cuántas plazas tiene.
 *
 * El colaborador guarda position_id y una copia del nombre en users.position,
 * que este modelo mantiene al día cuando se renombra el cargo.
 */
class Position extends Model
{
    use BelongsToTenant;

    /**
     * Cargos habituales en transporte y logística: [nombre, área sugerida, itinerante].
     * El área se vincula solo si existe en la empresa con ese nombre.
     */
    public const SUGGESTED = [
        ['Jefe de agencia', 'Administración', false],
        ['Supervisor de sede', 'Operaciones / Ruta', false],
        ['Coordinador de operaciones', 'Operaciones / Ruta', false],
        ['Atención al cliente', 'Atención al cliente / Caja', false],
        ['Cajero(a)', 'Atención al cliente / Caja', false],
        ['Boletero(a)', 'Atención al cliente / Caja', false],
        ['Almacenero', 'Almacén y estiba', false],
        ['Estibador', 'Almacén y estiba', false],
        ['Despachador', 'Almacén y estiba', false],
        ['Chofer', 'Operaciones / Ruta', true],
        ['Ayudante de reparto', 'Operaciones / Ruta', true],
        ['Motorizado / Courier', 'Operaciones / Ruta', true],
        ['Mecánico', 'Mantenimiento', false],
        ['Agente de seguridad', 'Seguridad', false],
        ['Personal de limpieza', 'Limpieza', false],
        ['Asistente administrativo', 'Administración', false],
    ];

    protected $fillable = ['tenant_id', 'department_id', 'name', 'code', 'description', 'is_roaming', 'is_active'];

    protected $casts = ['is_roaming' => 'boolean', 'is_active' => 'boolean'];

    protected static function booted(): void
    {
        // Quienes ya tenían ese cargo escrito a mano quedan vinculados al crearlo en el catálogo
        static::created(function (Position $position) {
            User::withoutGlobalScopes()
                ->where('tenant_id', $position->tenant_id)->whereNull('position_id')
                ->whereRaw('lower(trim(position)) = ?', [mb_strtolower(trim($position->name))])
                ->update(['position_id' => $position->id, 'position' => $position->name]);
        });

        // La copia del nombre en cada colaborador sigue al catálogo
        static::updated(function (Position $position) {
            if ($position->wasChanged('name')) {
                User::withoutGlobalScopes()->where('position_id', $position->id)->update(['position' => $position->name]);
            }
        });
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_positions')->withPivot(['planned_headcount', 'tenant_id'])->withTimestamps();
    }
}
