<?php

namespace App\Modules\Training\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un intento de cuestionario (auditoría): nota, aprobado y respuestas enviadas. Solo se inserta. */
class TrainingQuizAttempt extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'enrollment_id', 'course_id', 'user_id', 'score', 'passed', 'correct', 'total', 'answers'];

    protected $casts = [
        'score'      => 'integer',
        'passed'     => 'boolean',
        'correct'    => 'integer',
        'total'      => 'integer',
        'answers'    => 'array',
        'created_at' => 'datetime',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(TrainingEnrollment::class, 'enrollment_id');
    }
}
