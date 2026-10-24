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

    public function __construct(private readonly AttendanceCalendarService $calendar) {}

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
