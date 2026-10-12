<?php

namespace App\Modules\Onboarding\Notifications;

use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AppNotification;

/** A RRHH: un colaborador completó sus etapas y espera la aprobación para marcar */
class OnboardingSubmitted extends AppNotification
{
    public function __construct(public readonly User $employee) {}

    public function type(): string
    {
        return 'onboarding.submitted';
    }

    public function title(): string
    {
        return 'Incorporación por revisar';
    }

    public function body(): string
    {
        return "{$this->employee->full_name} completó su incorporación y espera tu aprobación para empezar a marcar.";
    }

    public function payload(): array
    {
        return ['user_id' => $this->employee->id, 'screen' => 'onboarding_review'];
    }
}
