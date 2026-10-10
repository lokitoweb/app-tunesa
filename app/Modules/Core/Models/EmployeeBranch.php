<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Services\OrgScope;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Asignación colaborador ↔ sede (employee_branch). Es un modelo para poder
 * vigilar la escritura: un usuario con alcance de zona o sede no puede asignar
 * a nadie a una sede fuera de su alcance, venga del panel o de la API.
 */
class EmployeeBranch extends Pivot
{
    protected $table = 'employee_branch';

    public $incrementing = true;

    protected static function booted(): void
    {
        static::saving(function (EmployeeBranch $pivot) {
            if (! app(OrgScope::class)->allowsBranch((int) $pivot->branch_id)) {
                throw new \DomainException('No se puede asignar una sede fuera de tu alcance.');
            }
        });
    }
}
