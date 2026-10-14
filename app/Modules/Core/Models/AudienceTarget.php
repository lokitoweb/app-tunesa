<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Regla de público de un contenido (ver Support\Audience) */
class AudienceTarget extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'target_type', 'target_id', 'target_value'];

    protected $casts = ['target_id' => 'integer'];

    public function targetable(): MorphTo
    {
        return $this->morphTo();
    }
}
