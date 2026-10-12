<?php

namespace App\Modules\Training\Notifications;

use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Training\Models\TrainingEnrollment;

/** Al colaborador: su capacitación vence pronto y no la terminó */
class TrainingDueSoon extends AppNotification
{
    public function __construct(public readonly TrainingEnrollment $enrollment) {}

    public function type(): string
    {
        return 'training.due_soon';
    }

    public function title(): string
    {
        return 'Tu capacitación vence pronto';
    }

    public function body(): string
    {
        return sprintf('"%s" vence el %s. Llevas %d%%.', $this->enrollment->course->title,
            $this->enrollment->due_date->format('d/m/Y'), $this->enrollment->progress);
    }

    public function payload(): array
    {
        return ['course_id' => $this->enrollment->course_id, 'screen' => 'trainings'];
    }
}
