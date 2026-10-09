<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo EmployeeShift
 * Asignación concreta de un turno a un empleado en una sucursal con vigencia.
 */
class EmployeeShift extends Model
{
    use SoftDeletes;

    protected $table = 'employee_shifts';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'shift_id',
        'branch_id',
        'effective_from',
        'effective_until',
        'assigned_by',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'effective_from'  => 'date',
        'effective_until' => 'date',
        'is_active'       => 'boolean',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

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

    /** Solo asignaciones actualmente vigentes */
    public function scopeCurrent($query)
    {
        $today = now()->toDateString();
        return $query->where('is_active', true)
            ->where('effective_from', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $today);
            });
    }
}
