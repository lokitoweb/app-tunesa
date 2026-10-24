<?php

namespace App\Modules\HR\Models;

use App\Modules\Core\Models\User;
use App\Modules\Core\Models\Concerns\BelongsToBranchScope;
use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\Concerns\HasDateOnlyColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo LeaveRequest
 * Solicitud de vacaciones, permiso o licencia médica de un empleado.
 *
 * @property int    $id
 * @property int    $tenant_id
 * @property int    $user_id
 * @property string $type
 * @property string $status
 */
class LeaveRequest extends Model
{
    use BelongsToTenant, HasDateOnlyColumns, SoftDeletes, BelongsToBranchScope;

    /** Alcance por sede: [columna de sede, columna de colaborador] (BelongsToBranchScope) */
    public const BRANCH_SCOPE = [null, 'user_id'];

    public const TYPES = ['vacation', 'permission', 'medical_leave'];

    /** Columnas DATE guardadas como Y-m-d (ver HasDateOnlyColumns) */
    protected array $dateOnly = ['start_date', 'end_date'];

    protected $fillable = [
        'tenant_id',
        'user_id',
        'type',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'days',
        'reason',
        'attachment_path',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'start_date'  => 'date',
        'end_date'    => 'date',
        'days'        => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            'vacation'      => 'Vacaciones',
            'permission'    => 'Permiso',
            'medical_leave' => 'Licencia médica',
            default         => $this->type,
        };
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Solicitudes que $user puede revisar. Fuente única para el panel
     * (Aprobaciones y su contador) y la app del supervisor:
     * - RR. HH. (leave.approve_all): todas las de la empresa.
     * - Jefe inmediato: las de su área (jefe de área) y las de sus sedes (supervisor).
     */
    public function scopeApprovableBy($query, User $user)
    {
        if ($user->hasPermission('leave.approve_all')) {
            return $query;
        }

        return $query->where(fn ($q) => $q
            ->whereHas('employee.branches', fn ($b) => $b->visibleTo($user))
            ->orWhereHas('employee.department', fn ($d) => $d->where('head_id', $user->id)));
    }

    /**
     * Quién revisa la solicitud, para mostrarlo claro al colaborador y en el
     * panel: su jefe inmediato (jefe de área o, si no hay, supervisor de sede)
     * y RR. HH. Requiere employee.department.head y employee.branches.supervisor.
     */
    public function reviewersLabel(): string
    {
        $employee = $this->employee;
        // users.department es una columna de texto: la relación se lee aparte
        $department = $employee?->relationLoaded('department') ? $employee->getRelation('department') : $employee?->department()->first();
        $head = $department?->head;
        $boss = $head && $head->id !== $employee->id
            ? "{$head->full_name} (jefe de área)"
            : (($s = $employee?->branches->first(fn ($b) => $b->supervisor && $b->supervisor->id !== $employee->id)?->supervisor)
                ? "{$s->full_name} (supervisor de sede)"
                : null);

        return $boss ? "{$boss} o RR. HH." : 'RR. HH.';
    }

    /** Solicitudes que se cruzan con el rango [from, to] */
    public function scopeOverlapping($query, string $from, string $to)
    {
        return $query->where('start_date', '<=', $to)
            ->where('end_date', '>=', $from);
    }
}
