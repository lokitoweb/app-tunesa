<?php

namespace App\Modules\Training\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Pregunta de opción múltiple del cuestionario final (correct_option: índice desde 0) */
class TrainingQuestion extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'course_id', 'position', 'question', 'options', 'correct_option'];

    protected $hidden = ['correct_option'];

    protected $casts = ['options' => 'array', 'correct_option' => 'integer', 'position' => 'integer'];
}
