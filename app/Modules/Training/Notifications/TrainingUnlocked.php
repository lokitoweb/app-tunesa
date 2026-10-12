<?php

namespace App\Modules\Training\Notifications;

use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Training\Models\TrainingEnrollment;

/** Al colaborador: RRHH le dio intentos extra en un cuestionario bloqueado */
class TrainingUnlocked extends AppNotification
{
    public function __construct(public readonly TrainingEnrollment $enrollment, public readonly int $extra) {}

    public function type(): string
    {
        return 'training.unlocked';
    }

    public function title(): string
    {
        return 'Puedes volver a rendir el cuestionario';
    }

    public function body(): string
    {
        $plural = $this->extra === 1 ? '1 intento más' : "{$this->extra} intentos más";

        return "RRHH te dio {$plural} para \"{$this->enrollment->course->title}\". Repasa las lecciones antes de rendirlo.";
    }

    public function payload(): array
    {
        return ['course_id' => $this->enrollment->course_id, 'screen' => 'trainings'];
    }
}
