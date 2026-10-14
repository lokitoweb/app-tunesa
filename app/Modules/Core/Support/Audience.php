<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Department;
use App\Modules\Core\Models\Scopes\TenantScope;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Público de un contenido: reglas de a quién se le muestra.
 *
 *   all        → todo el personal
 *   branch     → quienes trabajan en la sucursal target_id (asignación vigente)
 *   department → área target_id (users.department_id)
 *   position   → cargo target_value (sin distinguir mayúsculas)
 *   user       → la persona target_id
 *
 * Se guarda en audience_targets (polimórfica) a través de Concerns\HasAudience.
 */
final class Audience
{
    public const TYPES = [
        'all'        => 'Todo el personal',
        'branch'     => 'Sucursal',
        'department' => 'Área',
        'position'   => 'Cargo',
        'user'       => 'Persona',
    ];

    /** Reglas de audience_targets que cumple la persona */
    public static function matching(Builder $targets, User $user): Builder
    {
        $branchIds = self::branchIds($user);

        return $targets->where(function (Builder $q) use ($user, $branchIds) {
            $q->where('target_type', 'all')
                ->orWhere(fn ($w) => $w->where('target_type', 'user')->where('target_id', $user->id))
                ->when($branchIds, fn ($w) => $w->orWhere(fn ($b) => $b->where('target_type', 'branch')->whereIn('target_id', $branchIds)))
                ->when($user->department_id, fn ($w) => $w->orWhere(fn ($d) => $d->where('target_type', 'department')->where('target_id', $user->department_id)))
                ->when(filled($user->position), fn ($w) => $w->orWhere(fn ($p) => $p->where('target_type', 'position')
                    ->whereRaw('LOWER(TRIM(target_value)) = ?', [mb_strtolower(trim((string) $user->position))])));
        });
    }

    /** Sucursales vigentes de la persona */
    public static function branchIds(User $user): array
    {
        return DB::table('employee_branch')
            ->where('user_id', $user->id)
            ->where(fn ($w) => $w->whereNull('assigned_until')->orWhere('assigned_until', '>=', now()->toDateString()))
            ->pluck('branch_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Personas activas de la empresa que cumplen alguna regla */
    public static function users(Collection $targets, int $tenantId): Builder
    {
        $query = User::withoutGlobalScope(TenantScope::class)
            ->where('users.tenant_id', $tenantId)
            ->where('users.status', 'active')
            ->whereIn('users.role', User::CLOCKING_ROLES);

        if ($targets->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }
        if ($targets->contains('target_type', 'all')) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($targets) {
            foreach ($targets as $t) {
                match ($t->target_type) {
                    'user'       => $q->orWhere('users.id', $t->target_id),
                    'department' => $q->orWhere('users.department_id', $t->target_id),
                    'position'   => $q->orWhereRaw('LOWER(TRIM(users.position)) = ?', [mb_strtolower(trim((string) $t->target_value))]),
                    'branch'     => $q->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('employee_branch')
                        ->whereColumn('employee_branch.user_id', 'users.id')
                        ->where('employee_branch.branch_id', $t->target_id)
                        ->where(fn ($w) => $w->whereNull('employee_branch.assigned_until')->orWhere('employee_branch.assigned_until', '>=', now()->toDateString()))),
                    default      => null,
                };
            }
        });
    }

    /** Reglas del formulario → filas de audience_targets (sin duplicados; "todos" absorbe al resto) */
    public static function normalize(array $targets): array
    {
        $rows = collect($targets)->map(fn ($t) => [
            'target_type'  => $t['type'],
            'target_id'    => in_array($t['type'], ['branch', 'department', 'user'], true) ? (int) $t['id'] : null,
            'target_value' => $t['type'] === 'position' ? trim((string) ($t['value'] ?? '')) : null,
        ])->filter(fn ($r) => $r['target_type'] !== 'position' || $r['target_value'] !== '');

        if ($rows->contains('target_type', 'all')) {
            return [['target_type' => 'all', 'target_id' => null, 'target_value' => null]];
        }

        return $rows->unique(fn ($r) => $r['target_type'] . '|' . $r['target_id'] . '|' . mb_strtolower((string) $r['target_value']))->values()->all();
    }

    /** Reglas de validación del campo "targets" del formulario */
    public static function rules(bool $required = true): array
    {
        return [
            'targets'         => [$required ? 'required' : 'nullable', 'array', 'min:1', 'max:200'],
            'targets.*.type'  => ['required', Rule::in(array_keys(self::TYPES))],
            'targets.*.id'    => ['nullable', 'integer'],
            'targets.*.value' => ['nullable', 'required_if:targets.*.type,position', 'string', 'max:150'],
        ];
    }

    public const MESSAGES = [
        'targets.required'               => 'Elige a quién se le muestra.',
        'targets.min'                    => 'Elige a quién se le muestra.',
        'targets.*.value.required_if'    => 'Escribe el cargo.',
    ];

    /** Los ids de sucursal, área o persona deben ser de la empresa */
    public static function validateIds(array $targets, ?int $tenantId): void
    {
        foreach ($targets as $i => $t) {
            $table = ['branch' => 'branches', 'department' => 'departments', 'user' => 'users'][$t['type'] ?? ''] ?? null;
            if ($table && ! DB::table($table)->where('id', (int) ($t['id'] ?? 0))->where('tenant_id', $tenantId)->exists()) {
                throw ValidationException::withMessages(["targets.{$i}.id" => 'Elige una opción de la lista.']);
            }
        }
    }

    /** Opciones del selector de público en el panel */
    public static function formOptions(): array
    {
        return [
            'types'       => self::TYPES,
            'branches'    => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'positions'   => User::whereNotNull('position')->where('position', '!=', '')->where('status', 'active')
                ->distinct()->orderBy('position')->pluck('position'),
            'employees'   => User::whereIn('role', User::CLOCKING_ROLES)->where('status', 'active')
                ->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'employee_code']),
        ];
    }

    /** Reglas guardadas → formulario */
    public static function toForm(Collection $targets): array
    {
        return $targets->isEmpty()
            ? [['type' => 'all', 'id' => null, 'value' => null]]
            : $targets->map(fn ($t) => ['type' => $t->target_type, 'id' => $t->target_id, 'value' => $t->target_value])->values()->all();
    }

    /** Texto legible de las reglas ("Sucursal: Lima Centro") */
    public static function describe(Collection $targets): array
    {
        $branches = Branch::whereIn('id', $targets->where('target_type', 'branch')->pluck('target_id'))->pluck('name', 'id');
        $areas    = Department::whereIn('id', $targets->where('target_type', 'department')->pluck('target_id'))->pluck('name', 'id');
        $people   = User::whereIn('id', $targets->where('target_type', 'user')->pluck('target_id'))->get(['id', 'first_name', 'last_name'])->keyBy('id');

        return $targets->map(fn ($t) => match ($t->target_type) {
            'all'        => 'Todo el personal',
            'branch'     => 'Sucursal: ' . ($branches[$t->target_id] ?? '—'),
            'department' => 'Área: ' . ($areas[$t->target_id] ?? '—'),
            'position'   => 'Cargo: ' . $t->target_value,
            'user'       => 'Persona: ' . ($people[$t->target_id]?->full_name ?? '—'),
            default      => '',
        })->values()->all();
    }
}
