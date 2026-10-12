<?php

namespace App\Modules\Training\Notifications;

use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Training\Models\TrainingEnrollment;

/** A RRHH: un colaborador agotó los intentos del cuestionario y quedó bloqueado */
class TrainingLocked extends AppNotification
{
    public function __construct(public readonly TrainingEnrollment $enrollment) {}

    public function type(): string
    {
        return 'training.locked';
    }

    public function title(): string
    {
        return 'Cuestionario bloqueado';
    }

    public function body(): string
    {
        $e = $this->enrollment;

        return "{$e->user->full_name} no aprobó \"{$e->course->title}\" en {$e->attempts} intentos (mejor nota {$e->score}). Revisa si le das intentos extra.";
    }

    public function payload(): array
    {
        return ['course_id' => $this->enrollment->course_id, 'enrollment_id' => $this->enrollment->id, 'user_id' => $this->enrollment->user_id, 'screen' => 'training_review'];
    }
}
