<?php

namespace App\Modules\HR\Models;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
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
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'type',
        'start_time',
        'end_time',
        'crosses_midnight',
        'grace_period_minutes',
        'break_allowed',
        'break_duration_minutes',
        'working_days',
        'rotation_work_days',
        'rotation_rest_days',
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
        'rotation_work_days'     => 'integer',
        'rotation_rest_days'     => 'integer',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

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

    public function isRotating(): bool
    {
        return $this->type === 'rotating';
    }

    /**
     * Verifica si el turno es laborable en una fecha concreta.
     *
     * - fixed:    según working_days.
     * - rotating: ciclo de rotation_work_days de trabajo + rotation_rest_days
     *             de descanso, contado desde $anchor (primer día de trabajo).
     */
    public function appliesOnDate(CarbonInterface $date, ?CarbonInterface $anchor = null): bool
    {
        if (! $this->isRotating()) {
            return $this->appliesOnDay($date->dayOfWeek);
        }

        $work  = (int) $this->rotation_work_days;
        $cycle = $work + (int) $this->rotation_rest_days;

        if ($work < 1 || $cycle < 1 || ! $anchor) {
            return false;
        }

        $offset = (int) $anchor->copy()->startOfDay()->diffInDays($date->copy()->startOfDay(), false);
        if ($offset < 0) {
            return false;
        }

        return ($offset % $cycle) < $work;
    }
}
