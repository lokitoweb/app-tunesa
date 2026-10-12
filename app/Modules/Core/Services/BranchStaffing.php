<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Branch;
use Illuminate\Support\Facades\DB;

/**
 * Plantilla de una sede: plazas previstas por cargo frente al personal activo
 * que hoy tiene ese cargo en la sede. Incluye los cargos con personal que no
 * están en la plantilla (previstas = 0), para que el exceso también se vea.
 */
class BranchStaffing
{
    /** @return list<array{position_id: int, name: string, department: ?string, is_roaming: bool, planned: int, actual: int, gap: int}> */
    public function forBranch(Branch $branch): array
    {
        $actual = DB::table('employee_branch')
            ->join('users', 'users.id', '=', 'employee_branch.user_id')
            ->where('employee_branch.branch_id', $branch->id)
            ->where('users.status', 'active')->whereNull('users.deleted_at')
            ->whereNotNull('users.position_id')
            ->groupBy('users.position_id')
            ->pluck(DB::raw('count(*)'), 'users.position_id');

        $planned = DB::table('branch_positions')->where('branch_id', $branch->id)->pluck('planned_headcount', 'position_id');

        $ids = $planned->keys()->merge($actual->keys())->unique();
        if ($ids->isEmpty()) {
            return [];
        }

        return DB::table('positions')
            ->leftJoin('departments', 'departments.id', '=', 'positions.department_id')
            ->whereIn('positions.id', $ids)->where('positions.tenant_id', $branch->tenant_id)
            ->orderBy('positions.name')
            ->get(['positions.id', 'positions.name', 'positions.is_roaming', 'departments.name as department'])
            ->map(function ($p) use ($planned, $actual) {
                $plan = (int) ($planned[$p->id] ?? 0);
                $have = (int) ($actual[$p->id] ?? 0);

                return [
                    'position_id' => (int) $p->id,
                    'name'        => $p->name,
                    'department'  => $p->department,
                    'is_roaming'  => (bool) $p->is_roaming,
                    'planned'     => $plan,
                    'actual'      => $have,
                    // > 0: plazas por cubrir; < 0: personal por encima de lo previsto
                    'gap'         => $plan - $have,
                ];
            })->values()->all();
    }
}
