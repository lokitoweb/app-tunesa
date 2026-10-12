<?php

namespace App\Modules\Onboarding\Notifications;

use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Onboarding\Models\OnboardingProcess;

/** Al colaborador: RRHH aprobó u observó su incorporación */
class OnboardingReviewed extends AppNotification
{
    public function __construct(public readonly OnboardingProcess $process) {}

    public function type(): string
    {
        return 'onboarding.' . $this->process->status; // onboarding.approved | onboarding.rejected
    }

    public function title(): string
    {
        return $this->approved() ? 'Incorporación aprobada' : 'Incorporación observada';
    }

    public function body(): string
    {
        return $this->approved()
            ? 'RRHH aprobó tu incorporación. Ya puedes marcar tu asistencia.'
            : "RRHH observó tu incorporación. Motivo: {$this->process->rejection_reason}. Corrige lo indicado y vuelve a enviarla desde la app.";
    }

    public function payload(): array
    {
        return ['status' => $this->process->status, 'screen' => 'onboarding'];
    }

    private function approved(): bool
    {
        return $this->process->status === OnboardingProcess::APPROVED;
    }
}
