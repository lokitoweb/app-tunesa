<?php

namespace App\Modules\Core\Models\Concerns;

use App\Modules\Core\Models\Scopes\BranchScope;
use App\Modules\Core\Services\OrgScope;

/**
 * BelongsToBranchScope
 *
 * Para modelos que pertenecen a una sede (columna branch_id) y/o a un
 * colaborador (user_id). Con un usuario de alcance zonal o de sede:
 *  - Lectura: solo registros de sus sedes o de colaboradores asignados a ellas.
 *  - Escritura: impide guardar un registro de otra sede u otro colaborador.
 *
 * Por defecto usa branch_id y user_id si el modelo los declara en BRANCH_SCOPE:
 *   public const BRANCH_SCOPE = ['branch_id', 'user_id'];   // [sede|null, colaborador|null]
 */
trait BelongsToBranchScope
{
    public static function bootBelongsToBranchScope(): void
    {
        static::addGlobalScope(new BranchScope());

        static::saving(function ($model) {
            $scope = app(OrgScope::class);
            if (! $scope->restricted()) {
                return;
            }

            [$branchColumn, $userColumn] = $model->branchScopeColumns();
            $ownKey = $model->getKeyName();

            if ($branchColumn && $branchColumn !== $ownKey && $model->{$branchColumn} !== null
                && ! $scope->allowsBranch((int) $model->{$branchColumn})) {
                throw new \DomainException('No se puede guardar un registro de una sede fuera de tu alcance.');
            }
            if ($userColumn && $userColumn !== $ownKey && $model->{$userColumn} !== null
                && ! $scope->allowsUser((int) $model->{$userColumn})) {
                throw new \DomainException('No se puede guardar un registro de un colaborador fuera de tu alcance.');
            }
        });
    }

    /** @return array{0: ?string, 1: ?string} [columna de sede, columna de colaborador] */
    public function branchScopeColumns(): array
    {
        return defined(static::class . '::BRANCH_SCOPE') ? static::BRANCH_SCOPE : ['branch_id', null];
    }
}
