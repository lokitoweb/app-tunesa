<?php

namespace App\Modules\HR\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Concerns\BelongsToBranchScope;
use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\Concerns\HasDateOnlyColumns;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Horario de una persona en una fecha (QA Ronda 2, HQ-01): turno o día libre (shift_id NULL).
 * Manda sobre employee_shifts ese día cuando la empresa usa schedule_v2 (ScheduleResolver).
 */
class EmployeeScheduleDay extends Model
{
    use BelongsToTenant, HasDateOnlyColumns, BelongsToBranchScope;

    protected $fillable = ['tenant_id', 'user_id', 'work_date', 'shift_id', 'branch_id', 'source', 'notes', 'created_by', 'updated_by'];

    protected $casts = ['work_date' => 'date'];

    protected array $dateOnly = ['work_date'];

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

    /**
     * La fila como asignación de un solo día, para que los llamadores del resolver la
     * usen igual que una asignación semanal (turno, sede, worksOn ese día).
     */
    public function asAssignment(): EmployeeShift
    {
        $date = $this->work_date->toDateString();
        $assignment = new EmployeeShift([
            'tenant_id' => $this->tenant_id, 'user_id' => $this->user_id, 'shift_id' => $this->shift_id,
            'branch_id' => $this->branch_id, 'effective_from' => $date, 'effective_until' => $date, 'is_active' => true,
        ]);
        $assignment->scheduledDate = $date;
        $assignment->setRelation('shift', $this->shift?->forDate($date));

        return $assignment;
    }
}
