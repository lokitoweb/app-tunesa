<?php

namespace App\Modules\Training\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Regla de asignación de un curso:
 *   all        → todo el personal activo
 *   branch     → quienes trabajan en la sucursal target_id
 *   department → área target_id (users.department_id)
 *   position   → cargo target_value (users.position, sin distinguir mayúsculas)
 *   user       → la persona target_id
 */
class TrainingTarget extends Model
{
    use BelongsToTenant;

    public const TYPES = ['all', 'branch', 'department', 'position', 'user'];

    protected $fillable = ['tenant_id', 'course_id', 'target_type', 'target_id', 'target_value'];

    protected $casts = ['target_id' => 'integer'];
}
