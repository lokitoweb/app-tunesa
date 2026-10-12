<?php

namespace App\Modules\Training\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Curso asignado a una persona y su avance */
class TrainingEnrollment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'course_id', 'user_id', 'assigned_at', 'due_date', 'started_at', 'completed_at',
        'completed_lessons', 'progress', 'score', 'attempts', 'due_reminder_sent_at',
    ];

    protected $casts = [
        'assigned_at'          => 'datetime',
        'due_date'             => 'date',
        'started_at'           => 'datetime',
        'completed_at'         => 'datetime',
        'due_reminder_sent_at' => 'datetime',
        'completed_lessons'    => 'array',
        'progress'             => 'integer',
        'score'                => 'integer',
        'attempts'             => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class, 'course_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** pending | in_progress | completed | overdue */
    public function status(): string
    {
        if ($this->completed_at) {
            return 'completed';
        }
        if ($this->due_date && $this->due_date->endOfDay()->isPast()) {
            return 'overdue';
        }

        return $this->started_at ? 'in_progress' : 'pending';
    }

    public const STATUS_LABELS = [
        'pending'     => 'Sin empezar',
        'in_progress' => 'En curso',
        'completed'   => 'Completado',
        'overdue'     => 'Vencido',
    ];

    /** @return list<int> */
    public function completedLessonIds(): array
    {
        return array_values(array_map('intval', $this->completed_lessons ?? []));
    }
}
