<?php

namespace App\Modules\Training\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Curso de capacitación: lecciones, cuestionario opcional y a quién se asigna */
class TrainingCourse extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const CATEGORIES = [
        'induccion'      => 'Inducción',
        'seguridad'      => 'Seguridad y salud en el trabajo',
        'atencion'       => 'Atención al cliente',
        'procedimientos' => 'Procedimientos y operaciones',
        'producto'       => 'Productos y servicios',
        'normativa'      => 'Normativa y políticas',
        'general'        => 'General',
    ];

    public const STATUSES = ['draft' => 'Borrador', 'published' => 'Publicado', 'archived' => 'Archivado'];

    protected $fillable = [
        'tenant_id', 'title', 'description', 'category', 'estimated_minutes', 'is_mandatory',
        'due_days', 'passing_score', 'max_attempts', 'status', 'published_at', 'created_by',
    ];

    protected $casts = [
        'is_mandatory'      => 'boolean',
        'estimated_minutes' => 'integer',
        'due_days'          => 'integer',
        'passing_score'     => 'integer',
        'max_attempts'      => 'integer',
        'published_at'      => 'datetime',
    ];

    public function lessons(): HasMany
    {
        return $this->hasMany(TrainingLesson::class, 'course_id')->orderBy('position')->orderBy('id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(TrainingQuestion::class, 'course_id')->orderBy('position')->orderBy('id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(TrainingTarget::class, 'course_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(TrainingEnrollment::class, 'course_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function hasQuiz(): bool
    {
        return $this->relationLoaded('questions') ? $this->questions->isNotEmpty() : $this->questions()->exists();
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? self::CATEGORIES['general'];
    }
}
