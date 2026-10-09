<?php

namespace App\Models\Scopes;

use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * TenantScope (Global Scope)
 *
 * Filtra automáticamente toda consulta Eloquent por el tenant resuelto en la
 * request (ResolveTenant → TenantContext). Así, aunque un controlador olvide
 * el `where('tenant_id', ...)`, nunca devolverá filas de otra empresa.
 *
 * Fuera de una request con tenant (consola, seeders, panel web por sesión,
 * rutas del super admin) el scope no filtra.
 *
 * Para saltarlo a propósito: Model::withoutGlobalScope(TenantScope::class)
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->resolved()) {
            $builder->where($model->qualifyColumn('tenant_id'), $context->id());
        }
    }
}
