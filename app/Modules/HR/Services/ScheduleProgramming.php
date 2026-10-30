<?php

namespace App\Modules\HR\Services;

use App\Modules\Attendance\Services\AttendanceCalendarService;
use App\Modules\Attendance\Services\ScheduleResolver;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\HR\Models\EmployeeScheduleDay;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\LeaveRequest;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Programación por calendario (QA Ronda 2, HQ-05): agencia → horario → días.
 *
 * Guardar graba solo los días que se marcaron: un horario o día libre (shift_id NULL).
 * Ese día programado rige al instante en la app, la marcación, las faltas y los reportes
 * (ScheduleResolver); los días no programados siguen con la asignación semanal. Cada
 * guardado deja una entrada en la bitácora con quién, cuándo, el motivo y el antes/después
 * de cada día que cambió.
 */
class ScheduleProgramming
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ScheduleResolver $resolver,
    ) {}

    /** Colaboradores activos que marcan y están asignados a la agencia, dentro del alcance de quien mira */
    public function people(Branch $branch, User $viewer, string $from, string $to): Collection
    {
        return User::where('status', 'active')
            ->whereIn('role', User::CLOCKING_ROLES)
            ->whereHas('branches', fn ($q) => $q->where('branches.id', $branch->id)
                ->where(fn ($w) => $w->whereNull('employee_branch.assigned_from')->orWhere('employee_branch.assigned_from', '<=', $to))
                ->where(fn ($w) => $w->whereNull('employee_branch.assigned_until')->orWhere('employee_branch.assigned_until', '>=', $from)))
            ->visibleTo($viewer)
            ->orderBy('last_name')->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'employee_code', 'position', 'position_id', 'tenant_id']);
    }

    /**
     * Programación de cada persona en el rango: fila del día si existe (programado), si no
     * la asignación semanal o rotativa que trabaja ese día (heredado), si no nada.
     *
     * @return array<int, array<string, array{shift_id: ?int, source: string}>>  user_id => fecha => celda
     */
    public function grid(Collection $people, string $from, string $to): array
    {
        $ids = $people->pluck('id')->all();
        $days = EmployeeScheduleDay::whereIn('user_id', $ids)->whereBetween('work_date', [$from, $to])->get()
            ->groupBy('user_id');
        $assignments = EmployeeShift::whereIn('user_id', $ids)->where('is_active', true)
            ->where('effective_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $from))
            ->with('shift')->get()->groupBy('user_id');

        $vacations = $this->vacationDays($ids, $from, $to);

        $grid = [];
        foreach ($people as $person) {
            $mine = $days->get($person->id, collect())->keyBy(fn (EmployeeScheduleDay $d) => $d->work_date->toDateString());
            foreach (CarbonPeriod::create($from, $to) as $date) {
                $day = $date->toDateString();
                // Vacaciones aprobadas: el día queda bloqueado (no se programa encima)
                if (isset($vacations[$person->id][$day])) {
                    $grid[$person->id][$day] = ['shift_id' => null, 'source' => 'vacation'];

                    continue;
                }
                if ($row = $mine->get($day)) {
                    $grid[$person->id][$day] = ['shift_id' => $row->shift_id, 'source' => 'programmed'];

                    continue;
                }
                $working = $this->resolver->working($assignments->get($person->id, collect()), CarbonImmutable::parse($day));
                $grid[$person->id][$day] = ['shift_id' => $working?->shift_id, 'source' => $working ? 'assignment' : 'none'];
            }
        }

        return $grid;
    }

    /**
     * Graba solo los días enviados (los que se marcaron en la matriz): un horario, o null = día
     * libre puesto a propósito. Un día que no se envía no se toca: si no estaba programado
     * sigue con la asignación semanal. Así guardar el mes no borra días pasados ni copia
     * la asignación semanal como programación.
     *
     * @param  array<int, array<string, ?int>>  $values  user_id => fecha => shift_id (null = libre)
     * @return array{created: int, updated: int, unchanged: int, locked: int}
     */
    public function saveRange(User $editor, Branch $branch, array $userIds, string $from, string $to, array $values, string $reason): array
    {
        $sent = array_intersect_key($values, array_flip($userIds));

        return $this->write($editor, $branch, $sent, $reason, ['from' => $from, 'to' => $to]);
    }

    /**
     * Repite el patrón de [sourceFrom, sourceTo] día a día desde el día siguiente hasta $until
     * ("copiar semana" = una semana de origen hasta el fin de la semana destino).
     */
    public function repeat(User $editor, Branch $branch, Collection $people, string $sourceFrom, string $sourceTo, string $targetFrom, string $until, string $reason): array
    {
        $grid = $this->grid($people, $sourceFrom, $sourceTo);
        $length = (int) CarbonImmutable::parse($sourceFrom)->diffInDays(CarbonImmutable::parse($sourceTo)) + 1;

        // Solo se copian los días programados (horario o día libre): los heredados de la asignación
        // semanal o sin programar no se copian, así no se duplica la asignación como programación
        $values = [];
        foreach ($people as $person) {
            $pattern = array_values($grid[$person->id] ?? []);
            foreach (CarbonPeriod::create($targetFrom, $until) as $i => $date) {
                $cell = $pattern[$i % $length] ?? null;
                if ($cell && $cell['source'] === 'programmed') {
                    $values[$person->id][$date->toDateString()] = $cell['shift_id'];
                }
            }
        }

        return $this->write($editor, $branch, $values, $reason, ['source_from' => $sourceFrom, 'source_to' => $sourceTo, 'from' => $targetFrom, 'to' => $until]);
    }

    /**
     * Días de vacaciones aprobadas (módulo de licencias) de cada persona en el rango.
     *
     * @return array<int, array<string, true>>  user_id => fecha => true
     */
    public function vacationDays(array $userIds, string $from, string $to): array
    {
        $out = [];
        LeaveRequest::whereIn('user_id', $userIds)->where('status', 'approved')->where('type', 'vacation')
            ->overlapping($from, $to)->get(['user_id', 'start_date', 'end_date'])
            ->each(function (LeaveRequest $l) use (&$out, $from, $to) {
                $start = max($l->start_date->toDateString(), $from);
                $end = min($l->end_date->toDateString(), $to);
                if ($start > $end) {
                    return;
                }
                foreach (CarbonPeriod::create($start, $end) as $date) {
                    $out[$l->user_id][$date->toDateString()] = true;
                }
            });

        return $out;
    }

    private static function id(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /** @param  array<int, array<string, ?int>>  $values */
    private function write(User $editor, Branch $branch, array $values, string $reason, array $range): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'locked' => 0];
        $changes = [];
        $dates = collect($values)->flatMap(fn ($days) => array_keys($days));
        if ($dates->isEmpty()) {
            return $counts;
        }

        // Los días de vacaciones aprobadas no se sobrescriben (aunque el navegador los envíe)
        $vacations = $this->vacationDays(array_keys($values), $dates->min(), $dates->max());
        foreach ($values as $userId => $days) {
            foreach (array_keys($days) as $date) {
                if (isset($vacations[$userId][$date])) {
                    unset($values[$userId][$date]);
                    $counts['locked']++;
                }
            }
        }

        DB::transaction(function () use ($editor, $branch, $values, $reason, $dates, &$counts, &$changes) {
            $existing = EmployeeScheduleDay::whereIn('user_id', array_keys($values))
                ->whereBetween('work_date', [$dates->min(), $dates->max()])
                ->lockForUpdate()->get()
                ->keyBy(fn (EmployeeScheduleDay $d) => $d->user_id . '|' . $d->work_date->toDateString());

            foreach ($values as $userId => $days) {
                foreach ($days as $date => $shiftId) {
                    $shiftId = $shiftId ? (int) $shiftId : null;
                    $branchId = $shiftId ? $branch->id : null;
                    $row = $existing->get("{$userId}|{$date}");

                    if ($row && self::id($row->shift_id) === $shiftId && self::id($row->branch_id) === $branchId) {
                        $counts['unchanged']++;

                        continue;
                    }

                    $changes[] = ['user_id' => $userId, 'date' => $date, 'old' => $row ? ($row->shift_id ?? 'libre') : null, 'new' => $shiftId ?? 'libre'];
                    if ($row) {
                        $row->update(['shift_id' => $shiftId, 'branch_id' => $branchId, 'updated_by' => $editor->id, 'notes' => mb_substr($reason, 0, 300)]);
                        $counts['updated']++;
                    } else {
                        EmployeeScheduleDay::create([
                            'tenant_id' => $branch->tenant_id, 'user_id' => $userId, 'work_date' => $date, 'shift_id' => $shiftId,
                            'branch_id' => $branchId, 'source' => 'calendar', 'notes' => mb_substr($reason, 0, 300),
                            'created_by' => $editor->id, 'updated_by' => $editor->id,
                        ]);
                        $counts['created']++;
                    }
                }
            }
        });

        if ($changes) {
            // El calendario de asistencia guarda en caché los meses cerrados: se invalida cada mes que cambió
            foreach (collect($changes)->map(fn ($c) => substr($c['date'], 0, 7))->unique() as $month) {
                AttendanceCalendarService::bump((int) $branch->tenant_id, "{$month}-01");
            }
            $this->audit->log('schedule.programmed', $branch, $range + [
                'reason' => mb_substr($reason, 0, 500), 'changed_days' => count($changes), 'changes' => $changes,
            ]);
        }

        return $counts;
    }
}
