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
        return $this->shift?->appliesOnDate($date, $this->rotation_anchor_date ?? $this->effective_from) ?? false;
    }

    /** Solo asignaciones vigentes (hoy o en la fecha dada) */
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
