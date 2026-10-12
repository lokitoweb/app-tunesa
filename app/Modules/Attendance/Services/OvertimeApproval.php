<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Aprobación de horas extra por jefatura (Aprobaciones > Horas extra).
 *
 * Al cerrar la jornada, desde Attendance::OVERTIME_MIN_HOURS de más quedan
 * "por aprobar". El supervisor aprueba (todas o menos horas) o rechaza las de
 * su equipo; RRHH (leave.approve_all), las de toda la empresa. Nadie revisa
 * las suyas. A planilla van solo las aprobadas (Attendance::payableOvertimeSql).
 */
class OvertimeApproval
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Jornadas con horas extra que $user puede ver en la bandeja */
    public function query(User $user): Builder
    {
        return Attendance::query()
            ->whereNotNull('overtime_status')
            ->when(! $user->can('leave.approve_all'), fn ($q) => $q->whereHas('user', fn ($u) => $u->visibleTo($user)));
    }

    public function pendingCount(User $user): int
    {
        return $this->query($user)->where('overtime_status', Attendance::OVERTIME_PENDING)
            ->where('user_id', '!=', $user->id)->count();
    }

    /**
     * @param  'approved'|'rejected'  $decision
     * @param  float|null  $hours  horas a aprobar (por defecto todas las calculadas)
     *
     * @throws ValidationException
     */
    public function review(Attendance $attendance, User $reviewer, string $decision, ?float $hours = null, ?string $notes = null): void
    {
        if ((int) $attendance->user_id === (int) $reviewer->id) {
            throw ValidationException::withMessages(['decision' => 'No puedes aprobar tus propias horas extra.']);
        }
        if ($attendance->overtime_status !== Attendance::OVERTIME_PENDING) {
            throw ValidationException::withMessages(['decision' => 'Estas horas extra ya fueron revisadas.']);
        }

        $calculated = (float) $attendance->overtime_hours;
        $approved = $decision === Attendance::OVERTIME_APPROVED ? round(min($hours ?? $calculated, $calculated), 2) : null;

        if ($approved !== null && $approved <= 0) {
            throw ValidationException::withMessages(['hours' => 'Para no pagar ninguna hora, rechaza las horas extra.']);
        }
        if ($decision === Attendance::OVERTIME_REJECTED && ! trim((string) $notes)) {
            throw ValidationException::withMessages(['notes' => 'Indica el motivo del rechazo.']);
        }

        $attendance->update([
            'overtime_status'         => $decision,
            'overtime_approved_hours' => $approved,
            'overtime_reviewed_by'    => $reviewer->id,
            'overtime_reviewed_at'    => now(),
            'overtime_review_notes'   => $notes ? mb_substr(trim($notes), 0, 500) : null,
        ]);

        $action = $decision === Attendance::OVERTIME_APPROVED ? 'attendance.overtime_approved' : 'attendance.overtime_rejected';
        $this->audit->log($action, $attendance, [
            'employee_id'      => $attendance->user_id,
            'work_date'        => $attendance->work_date?->toDateString(),
            'calculated_hours' => $calculated,
            'approved_hours'   => $approved,
        ]);
    }
}
