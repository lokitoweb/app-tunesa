<?php

namespace App\Modules\Training\Notifications;

use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Training\Models\TrainingCourse;
use Illuminate\Support\Carbon;

/** Al colaborador: le asignaron una capacitación */
class TrainingAssigned extends AppNotification
{
    public function __construct(public readonly TrainingCourse $course, public readonly ?string $dueDate = null) {}

    public function type(): string
    {
        return 'training.assigned';
    }

    public function title(): string
    {
        return $this->course->is_mandatory ? 'Nueva capacitación obligatoria' : 'Nueva capacitación disponible';
    }

    public function body(): string
    {
        $due = $this->dueDate ? ' Complétala antes del ' . Carbon::parse($this->dueDate)->format('d/m/Y') . '.' : '';

        return "Te asignaron \"{$this->course->title}\".{$due}";
    }

    public function payload(): array
    {
        return ['course_id' => $this->course->id, 'screen' => 'trainings'];
    }
}
