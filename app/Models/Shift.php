<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modelo Shift (Turno de Trabajo)
 * Define plantillas de horarios reutilizables.
 *
 * @property int    $id
 * @property int    $tenant_id
 * @property string $name
 * @property string $start_time
 * @property string $end_time
 * @property int    $grace_period_minutes
 */
class Shift extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'start_time',
        'end_time',
        'crosses_midnight',
        'grace_period_minutes',
        'break_allowed',
        'break_duration_minutes',
        'working_days',
        'daily_hours',
        'color',
        'is_active',
        'description',
    ];

    protected $casts = [
        'working_days'           => 'array',
        'crosses_midnight'       => 'boolean',
        'break_allowed'          => 'boolean',
        'is_active'              => 'boolean',
        'daily_hours'            => 'decimal:2',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function employeeShifts(): HasMany
    {
        return $this->hasMany(EmployeeShift::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Verifica si el turno aplica en un día de la semana dado.
     * @param int $dayOfWeek 0=Domingo ... 6=Sábado
     */
    public function appliesOnDay(int $dayOfWeek): bool
    {
        return in_array($dayOfWeek, $this->working_days ?? []);
    }
}
