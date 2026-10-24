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
