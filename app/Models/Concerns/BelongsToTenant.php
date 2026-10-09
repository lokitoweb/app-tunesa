<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BelongsToTenant
 *
 * Trait para todo modelo con columna tenant_id:
 *  - Registra el TenantScope global (aislamiento en lectura).
 *  - Completa tenant_id al crear si no se indicó (aislamiento en escritura).
 *  - Impide guardar un registro con un tenant_id distinto al de la request.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function ($model) {
            $context = app(TenantContext::class);

            if ($context->resolved() && empty($model->tenant_id)) {
                $model->tenant_id = $context->id();
            }
        });

        static::saving(function ($model) {
            $context = app(TenantContext::class);

            if ($context->resolved()
                && ! is_null($model->tenant_id)
                && (int) $model->tenant_id !== $context->id()) {
                throw new \DomainException('No se puede guardar un registro de otra empresa.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
