<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Permiso puntual para marcar un día sin turno programado (lo da RRHH o Gerencia) */
class OffShiftAuthorization extends Model
{
    use BelongsToTenant;

    protected $table = 'attendance_offshift_authorizations';

    protected $fillable = ['tenant_id', 'user_id', 'work_date', 'reason', 'authorized_by'];

    protected $casts = ['work_date' => 'date'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }
}
