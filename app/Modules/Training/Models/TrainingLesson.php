<?php

namespace App\Modules\Training\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lección de un curso: texto, video (enlace), PDF (disco privado) o enlace externo */
class TrainingLesson extends Model
{
    use BelongsToTenant;

    public const TYPES = ['text' => 'Texto', 'video' => 'Video', 'pdf' => 'Documento PDF', 'link' => 'Enlace'];

    protected $fillable = [
        'tenant_id', 'course_id', 'position', 'title', 'type', 'content', 'url',
        'file_disk', 'file_path', 'file_name', 'file_size', 'duration_minutes',
    ];

    protected $hidden = ['file_disk', 'file_path'];

    protected $casts = ['position' => 'integer', 'file_size' => 'integer', 'duration_minutes' => 'integer'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class, 'course_id');
    }
}
