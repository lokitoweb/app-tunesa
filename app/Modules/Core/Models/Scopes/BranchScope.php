<?php

namespace App\Modules\Core\Models\Scopes;

use App\Modules\Core\Services\OrgScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Segunda barrera después de TenantScope: un usuario con alcance de zona o de
 * sede solo lee registros de sus sedes o de los colaboradores asignados a ellas.
 * Ver el trait BelongsToBranchScope y App\Modules\Core\Services\OrgScope.
 *
 * Para saltarlo a propósito: Model::withoutGlobalScope(BranchScope::class)
 */
class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $scope = app(OrgScope::class);

        if (! $scope->restricted()) {
            return;
        }

        [$branchColumn, $userColumn] = $model->branchScopeColumns();

        $builder->where(function (Builder $query) use ($model, $scope, $branchColumn, $userColumn) {
            if ($branchColumn) {
                $query->whereIn($model->qualifyColumn($branchColumn), $scope->branchIds());
            }
            if ($userColumn) {
                $query->orWhereIn($model->qualifyColumn($userColumn), $scope->userIds());
            }
            // Uno mismo siempre se ve (el modelo User, aunque no esté asignado a la sede)
            if ($userColumn === $model->getKeyName()) {
                $query->orWhere($model->getQualifiedKeyName(), Auth::id());
            }
        });
    }
}
