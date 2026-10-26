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
        'branch_id',
        'name',
        'code',
        'type',
        'profile',
        'start_time',
        'end_time',
        'crosses_midnight',
        'grace_period_minutes',
        'break_allowed',
        'break_duration_minutes',
        'working_days',
        'day_schedules',
        'rotation_work_days',
        'rotation_rest_days',
        'daily_hours',
        'color',
        'is_active',
        'description',
    ];

    protected $casts = [
        'working_days'           => 'array',
        'day_schedules'          => 'array',
        'crosses_midnight'       => 'boolean',
        'break_allowed'          => 'boolean',
        'is_active'              => 'boolean',
        'daily_hours'            => 'decimal:2',
        'rotation_work_days'     => 'integer',
        'rotation_rest_days'     => 'integer',
    ];

    /** Perfiles de turno: agrupan horarios del mismo tipo de personal */
    public const PROFILES = [
        'administrative' => 'Administrativo',
        'sales'          => 'Ventas / tienda',
        'operations'     => 'Operaciones / campo',
    ];

    /** Lo que un día de la semana puede cambiar respecto del horario general */
    public const DAY_FIELDS = ['start_time', 'end_time', 'break_duration_minutes', 'daily_hours'];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    /** Agencia del horario (NULL = horario anterior, disponible en todas las agencias) */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Core\Models\Branch::class);
    }

    /** Horarios que se ofrecen en una agencia: los suyos y los que no tienen agencia */
    public function scopeForBranch($query, ?int $branchId)
    {
        return $query->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($w) => $w->orWhere('branch_id', $branchId)));
    }

    public function employeeShifts(): HasMany
    {
        return $this->hasMany(EmployeeShift::class);
    }

    /** No se elimina si tiene asignaciones vigentes o días programados desde hoy en el calendario (QA Ronda 2) */
    public function inUse(): bool
    {
        return $this->employeeShifts()->current()->exists()
            || EmployeeScheduleDay::where('shift_id', $this->id)->where('work_date', '>=', now(tenant_timezone())->toDateString())->exists();
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

    /**
     * El turno tal como rige en una fecha: con el horario propio de ese día de la
     * semana si lo tiene (sábado corto, por ejemplo). Tardanza, break, horas extra,
     * recordatorios y tablero leen el turno a través de aquí. Sin cambios ese día
     * devuelve el mismo modelo; si no, una copia que nunca se guarda.
     */
    public function forDate(CarbonInterface|string|null $date): static
    {
        if ($date === null || $this->isRotating()) {
            return $this;
        }

        $day = is_string($date) ? \Carbon\Carbon::parse($date)->dayOfWeek : $date->dayOfWeek;
        $override = array_intersect_key((array) (($this->day_schedules ?? [])[$day] ?? []), array_flip(self::DAY_FIELDS));
        $override = array_filter($override, fn ($v) => $v !== null && $v !== '');
        if ($override === []) {
            return $this;
        }

        $copy = clone $this;
        $copy->forceFill($override);
        if (isset($override['start_time']) || isset($override['end_time'])) {
            $copy->crosses_midnight = substr((string) $copy->end_time, 0, 5) <= substr((string) $copy->start_time, 0, 5);
        }

        return $copy;
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
