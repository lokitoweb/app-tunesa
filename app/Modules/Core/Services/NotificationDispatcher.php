<?php

namespace App\Modules\Core\Services;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\Branch;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Core\Jobs\NotifyAppUpdate;
use App\Modules\Core\Models\Scopes\TenantScope;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Notifications\ShiftAssigned;
use App\Modules\Core\Models\User;
use App\Modules\HR\Notifications\DocumentReviewed;
use App\Modules\Attendance\Notifications\JustificationReviewed;
use App\Modules\HR\Notifications\LeaveRequestReviewed;
use App\Modules\HR\Notifications\LeaveRequestSubmitted;
use App\Modules\Payroll\Notifications\PayslipPublished;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Envía las notificaciones in-app (canal database) a partir de los eventos
 * de los modelos (ver AppServiceProvider). Así cubre por igual la API, el
 * panel web y la carga masiva sin repetir código en cada controlador.
 *
 * Un fallo al notificar nunca debe tumbar la acción principal (aprobar,
 * publicar...): se registra en el log y se sigue.
 */
class NotificationDispatcher
{
    public function leaveCreated(LeaveRequest $leave): void
    {
        if ($leave->status !== 'pending') {
            return;
        }

        $this->safely(function () use ($leave) {
            $recipients = $this->leaveApprovers($leave);

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new LeaveRequestSubmitted($leave->loadMissing('employee')));
            }
        });
    }

    public function leaveUpdated(LeaveRequest $leave): void
    {
        if ($leave->wasChanged('status') && in_array($leave->status, ['approved', 'rejected'], true)) {
            $this->safely(fn () => $this->employee($leave->user_id)?->notify(new LeaveRequestReviewed($leave)));
        }
    }

    public function payslipSaved(Payslip $payslip, bool $created = false): void
    {
        $justPublished = $payslip->status === 'published'
            && ($created || $payslip->wasChanged('status'));

        if ($justPublished) {
            $this->safely(fn () => $this->employee($payslip->user_id)?->notify(new PayslipPublished($payslip)));
        }
    }

    public function documentUpdated(EmployeeDocument $document): void
    {
        if ($document->wasChanged('status') && in_array($document->status, ['approved', 'rejected'], true)) {
            $this->safely(fn () => $this->employee($document->user_id)?->notify(new DocumentReviewed($document)));
        }
    }

    public function attendanceUpdated(Attendance $attendance): void
    {
        if ($attendance->wasChanged('justification_status')
            && in_array($attendance->justification_status, ['approved', 'rejected'], true)) {
            $this->safely(fn () => $this->employee($attendance->user_id)?->notify(new JustificationReviewed($attendance)));
        }
    }

    /** Turno nuevo o cambio de horario: aviso al colaborador (si sigue vigente) */
    public function shiftAssigned(EmployeeShift $assignment, bool $created = false): void
    {
        $changed = $created || $assignment->wasChanged(['shift_id', 'effective_from']);
        $current = $assignment->is_active
            && (! $assignment->effective_until || ! $assignment->effective_until->isPast());

        if ($changed && $current) {
            $this->safely(fn () => $this->employee($assignment->user_id)?->notify(new ShiftAssigned($assignment->loadMissing('shift'))));
        }
    }

    /**
     * Nueva "Última versión" en Marca y app: push a los colaboradores de esa
     * empresa (o de las que heredan de la plataforma). Solo si la versión sube.
     */
    public function appSettingsSaved(TenantSetting $row): void
    {
        $version = $row->latest_version;
        if (! $row->wasChanged('latest_version') || blank($version)) {
            return;
        }

        $previous = $row->getOriginal('latest_version');
        if ($previous && version_compare(TenantSetting::normalizeVersion($version), TenantSetting::normalizeVersion($previous), '<=')) {
            return;
        }

        $this->safely(fn () => NotifyAppUpdate::dispatch($row->tenant_id, $version)->afterCommit());
    }

    /**
     * Supervisores de las sucursales del colaborador + RRHH de la empresa
     * (si la empresa no tiene RRHH, su administrador). Nunca el propio solicitante.
     */
    private function leaveApprovers(LeaveRequest $leave): Collection
    {
        $tenantId = $leave->tenant_id;

        $supervisorIds = Branch::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereNotNull('supervisor_id')
            ->whereHas('employees', fn ($q) => $q->where('users.id', $leave->user_id))
            ->pluck('supervisor_id');

        $users = fn () => User::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('id', '!=', $leave->user_id);

        $hr = $users()->where('role', 'hr_manager')->pluck('id');
        if ($hr->isEmpty()) {
            $hr = $users()->where('role', 'company_admin')->pluck('id');
        }

        return $users()->whereIn('id', $supervisorIds->merge($hr)->unique())->get();
    }

    private function employee(int $userId): ?User
    {
        return User::withoutGlobalScope(TenantScope::class)->where('status', 'active')->find($userId);
    }

    private function safely(callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar una notificación: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
