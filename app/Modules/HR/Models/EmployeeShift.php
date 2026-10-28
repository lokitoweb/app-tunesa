<?php

namespace App\Modules\HR\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Models\Concerns\BelongsToBranchScope;
use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\Concerns\HasDateOnlyColumns;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo EmployeeShift
 * Asignación concreta de un turno a un empleado en una sucursal con vigencia.
 */
class EmployeeShift extends Model
{
    use BelongsToTenant, HasDateOnlyColumns, SoftDeletes, BelongsToBranchScope;

    /** Alcance por sede: [columna de sede, columna de colaborador] (BelongsToBranchScope) */
    public const BRANCH_SCOPE = ['branch_id', 'user_id'];

    protected $table = 'employee_shifts';

    /** Columnas DATE guardadas como Y-m-d (ver HasDateOnlyColumns) */
    protected array $dateOnly = ['effective_from', 'effective_until', 'rotation_anchor_date'];

    /** Fecha única cuando la asignación sale de una fila del calendario (EmployeeScheduleDay::asAssignment) */
    public ?string $scheduledDate = null;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'shift_id',
        'branch_id',
        'effective_from',
        'effective_until',
        'rotation_anchor_date',
        'assigned_by',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'effective_from'  => 'date',
        'effective_until' => 'date',
        'rotation_anchor_date' => 'date',
        'is_active'       => 'boolean',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /**
     * ¿El empleado trabaja este turno en la fecha dada?
     * En rotativos el ciclo arranca en rotation_anchor_date (o effective_from).
     */
    public function worksOn(CarbonInterface $date): bool
    {
        if ($this->scheduledDate !== null) {
            return $date->toDateString() === $this->scheduledDate;
        }

        return $this->shift?->appliesOnDate($date, $this->rotation_anchor_date ?? $this->effective_from) ?? false;
    }

    /** Solo asignaciones vigentes (hoy o en la fecha dada) */
    /**
     * Asigna un turno (una sola regla para el formulario de colaborador, Horarios y la API). Con
     * $replaceCurrent la asignación vigente se cierra el día anterior al nuevo turno: nunca hay dos
     * el mismo día. La rotación arranca el día del nuevo turno salvo que se indique otra fecha.
     *
     * @param  array{shift_id: int, branch_id: int, effective_from: string, effective_until?: ?string,
     *               rotation_anchor_date?: ?string, notes?: ?string, tenant_id?: int}  $attrs
     */
    public static function assign(int $userId, array $attrs, ?int $assignedBy, bool $replaceCurrent = true): self
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($userId, $attrs, $assignedBy, $replaceCurrent) {
            $from = \Illuminate\Support\Carbon::parse($attrs['effective_from'])->toDateString();
            if ($replaceCurrent) {
                static::where('user_id', $userId)->current($from)->lockForUpdate()->get()
                    ->each->update(['is_active' => false, 'effective_until' => \Illuminate\Support\Carbon::parse($from)->subDay()->toDateString()]);
            }

            return static::create(array_filter([
                'tenant_id'            => $attrs['tenant_id'] ?? null,
                'user_id'              => $userId,
                'shift_id'             => $attrs['shift_id'],
                'branch_id'            => $attrs['branch_id'],
                'effective_from'       => $from,
                'effective_until'      => $attrs['effective_until'] ?? null,
                'rotation_anchor_date' => $attrs['rotation_anchor_date'] ?? $from,
                'notes'                => $attrs['notes'] ?? null,
                'assigned_by'          => $assignedBy,
                'is_active'            => true,
            ], fn ($v) => $v !== null));
        });
    }

    public function scopeCurrent($query, ?string $date = null)
    {
        $today = $date ?? now()->toDateString();
        return $query->where('is_active', true)
            ->where('effective_from', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $today);
            });
    }
}
