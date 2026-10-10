<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Alcance territorial del usuario autenticado (users.scope_type):
 *
 *   global → toda la empresa (sin restricción)
 *   zone   → las sedes de su zona
 *   branch → su sede
 *
 * Lo consume el global scope BranchScope. Devuelve subconsultas (no listas de
 * ids) para que una sede movida de zona se refleje en la misma request.
 * Sin usuario autenticado (consola, colas, login) no restringe nada.
 */
class OrgScope
{
    /** Sedes visibles como subconsulta de ids, o null si no hay restricción */
    public function branchIds(): ?Builder
    {
        // hasUser(): no dispara la carga del usuario (que consulta User y volvería a entrar aquí)
        $user = Auth::hasUser() ? Auth::user() : null;

        if (! $user instanceof User || ! $user->isOrgScoped()) {
            return null;
        }

        $branches = DB::table('branches')->where('tenant_id', $user->tenant_id)->whereNull('deleted_at')->select('id');

        return $user->scope_type === User::SCOPE_ZONE
            ? $branches->where('zone_id', $user->scope_zone_id ?? 0)
            : $branches->where('id', $user->scope_branch_id ?? 0);
    }

    /** Colaboradores asignados a esas sedes (subconsulta de user_id), o null sin restricción */
    public function userIds(): ?Builder
    {
        $branches = $this->branchIds();

        return $branches ? DB::table('employee_branch')->whereIn('branch_id', $branches)->select('user_id') : null;
    }

    public function restricted(): bool
    {
        return $this->branchIds() !== null;
    }

    /** ¿Puede el usuario actual operar sobre esta sede? */
    public function allowsBranch(?int $branchId): bool
    {
        $branches = $this->branchIds();

        return $branches === null || ($branchId !== null && $branches->where('id', $branchId)->exists());
    }

    /** ¿Puede el usuario actual operar sobre este colaborador? */
    public function allowsUser(?int $userId): bool
    {
        $users = $this->userIds();

        return $users === null || $userId === Auth::id() || ($userId !== null && $users->where('user_id', $userId)->exists());
    }
}
