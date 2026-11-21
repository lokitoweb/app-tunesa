<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Faltas de un rango de fechas: días con turno programado sin marcación
 * (y las faltas ya registradas). Usa el mismo cálculo que el calendario
 * (AttendanceCalendarService), así que ambos muestran siempre lo mismo,
 * aunque el cierre nocturno (attendance:mark-absences) no haya corrido.
 */
class AbsenceService
{
    public const MAX_DAYS = 62;

    public function __construct(
        private readonly AttendanceCalendarService $calendar,
        private readonly \App\Modules\Core\Services\AuditLogger $audit,
    ) {}

    /** Faltas que registró el sistema y nadie tocó: sin marcación, sin corrección manual y sin justificación enviada */
    public static function automatic(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNull('clock_in_at')->whereNull('manual_edited_at')
            ->whereIn('status', ['absent', 'excused'])
            ->where(fn ($q) => $q->whereNull('justification_status')->orWhere('justification_status', 'none'));
    }

    /**
     * Anula las faltas automáticas de días pasados programados como «libre». El cierre nocturno registra la falta
     * de quien debía trabajar y no marcó; si después se guarda ese día como libre, la falta contradecía la
     * programación. Solo con evidencia explícita (fila «libre» en la Programación de horarios de esa persona y
     * fecha): no se deduce nada de turnos fijos, feriados ni días sin programar. Cada una queda en la bitácora.
     * Devuelve cuántas; $dryRun solo cuenta.
     */
    public function voidOnDaysOff(\Illuminate\Database\Eloquent\Builder $candidates, string $reason, bool $dryRun = false): int
    {
        $today = CarbonImmutable::now(tenant_timezone())->toDateString();
        $voided = 0;

        self::automatic($candidates)->where('work_date', '<', $today)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('employee_schedule_days as day_off')
                ->whereColumn('day_off.user_id', 'attendances.user_id')
                ->whereColumn('day_off.work_date', 'attendances.work_date')
                ->whereNull('day_off.shift_id'))
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($reason, $dryRun, &$voided) {
                foreach ($rows as $row) {
                    $voided++;
                    if ($dryRun) {
                        continue;
                    }
                    $this->audit->log('attendance.absence_voided', $row, [
                        'employee_id' => $row->user_id, 'work_date' => $row->work_date->toDateString(),
                        'status' => $row->status, 'shift_id' => $row->shift_id, 'branch_id' => $row->branch_id,
                        'notes' => $row->notes, 'reason' => $reason,
                    ]);
                    // Borrado definitivo: la falta nunca debió existir y el día queda libre para una marcación
                    // posterior (índice único por persona, sede y fecha). La bitácora guarda lo que había.
                    $row->forceDelete();
                }
            });

        return $voided;
    }

    /**
     * @return array{items: list<array>, total: int, people: int}
     */
    public function between(string $from, string $to, ?int $tenantId, User $viewer, ?int $branchId = null, ?string $search = null): array
    {
        $items = [];
        $start = CarbonImmutable::parse($from)->startOfMonth();
        $end   = CarbonImmutable::parse($to);

        for ($month = $start; $month->lte($end); $month = $month->addMonth()) {
            $filters = new CalendarFilters($tenantId, $month->format('Y-m'), $branchId, null, null, null, $search ?: null, $viewer);

            foreach ($this->calendar->each($filters) as $row) {
                foreach ($row['cells'] as $date => $cell) {
                    if ($cell === null || $cell['state'] !== 'absent' || $date < $from || $date > $to) {
                        continue;
                    }
                    $items[] = [
                        'user_id'       => $row['user_id'],
                        'tenant_id'     => $row['tenant_id'],
                        'name'          => $row['name'],
                        'employee_code' => $row['employee_code'],
                        'position'      => $row['position'],
                        'avatar_url'    => $row['avatar_url'],
                        'date'          => $date,
                        'shift'         => $cell['shift'],
                        'shift_start'   => $cell['shift_start'],
                        'attendance_id' => $cell['attendance_id'],
                        'note'          => $cell['note'],
                    ];
                }
            }
        }

        // Lo más reciente primero; dentro del día, por apellido
        usort($items, fn ($a, $b) => [$b['date'], $a['name']] <=> [$a['date'], $b['name']]);

        return ['items' => $items, 'total' => count($items), 'people' => count(array_unique(array_column($items, 'user_id')))];
    }

    /**
     * Mes de una persona según el calendario. Antes de devolverlo registra
     * las faltas de días pasados que aún no tienen fila (lo mismo que hace el
     * cierre nocturno attendance:mark-absences), para que la app las liste y
     * se puedan justificar. Idempotente.
     *
     * @return array{cells: array<string, ?array>, totals: array}
     */
    public function monthFor(User $user, int $year, int $month): array
    {
        $filters = new CalendarFilters($user->tenant_id, sprintf('%04d-%02d', $year, $month), userId: $user->id);
        $today = CarbonImmutable::now(tenant_timezone())->toDateString();
        $row = $this->calendar->rows(collect([$user]), $filters)[0] ?? null;

        $created = false;
        foreach (($row['cells'] ?? []) as $date => $cell) {
            if ($cell === null || $cell['state'] !== 'absent' || $cell['attendance_id'] || $date >= $today) {
                continue;
            }
            [$assignment] = app(AttendanceService::class)->getCurrentShift($user, $date);
            if (! $assignment?->branch_id) {
                continue;
            }
            \App\Modules\Attendance\Models\Attendance::firstOrCreate(
                ['user_id' => $user->id, 'work_date' => $date],
                ['tenant_id' => $user->tenant_id, 'branch_id' => $assignment->branch_id, 'shift_id' => $assignment->shift_id,
                    'status' => 'absent', 'notes' => 'Falta registrada automáticamente: no marcó entrada.'],
            );
            $created = true;
        }

        if ($created) {
            $row = $this->calendar->rows(collect([$user]), $filters)[0] ?? $row;
        }

        return ['cells' => $row['cells'] ?? [], 'totals' => $row['totals'] ?? []];
    }

    public function paginate(array $items, int $page, int $perPage = 25): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            array_slice($items, ($page - 1) * $perPage, $perPage),
            count($items),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }
}
