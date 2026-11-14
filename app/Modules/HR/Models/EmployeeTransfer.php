<?php

namespace App\Modules\HR\Models;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Traslado entre empresas del grupo: enlaza el registro de origen (cesado) con el de destino.
 * Cruza empresas a propósito: no lleva el alcance por empresa; se consulta por from_user_id / to_user_id.
 */
class EmployeeTransfer extends Model
{
    protected $fillable = ['from_tenant_id', 'to_tenant_id', 'from_user_id', 'to_user_id', 'effective_date',
        'documents_copied', 'onboarding_required', 'notes', 'created_by'];

    protected $casts = ['effective_date' => 'date', 'onboarding_required' => 'boolean', 'documents_copied' => 'integer'];

    public function fromTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'from_tenant_id');
    }

    public function toTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'to_tenant_id');
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id')->withoutGlobalScopes();
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id')->withoutGlobalScopes();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withoutGlobalScopes();
    }
}
