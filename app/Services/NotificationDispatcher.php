<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\EmployeeDocument;
use App\Models\LeaveRequest;
use App\Models\Payslip;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Notifications\DocumentReviewed;
use App\Notifications\JustificationReviewed;
use App\Notifications\LeaveRequestReviewed;
use App\Notifications\LeaveRequestSubmitted;
use App\Notifications\PayslipPublished;
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
