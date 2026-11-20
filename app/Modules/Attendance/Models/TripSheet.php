<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Hoja de viajes firmada por los choferes, escaneada o en foto (evidencia de la Bitácora de viajes) */
class TripSheet extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'period_from', 'period_to', 'file_path', 'original_name', 'mime_type', 'size_bytes', 'notes', 'uploaded_by',
    ];

    protected $casts = ['period_from' => 'date', 'period_to' => 'date'];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
