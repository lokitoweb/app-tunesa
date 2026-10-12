<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Holiday;
use App\Modules\HR\Models\LeaveRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Calendario con semáforo (Asistencia > Calendario): una fila por colaborador
 * y una celda por día del mes, con el estado que ya guarda `attendances`.
 *
 * Por página arma el mes con un número fijo de consultas, sin importar
 * cuántos colaboradores haya: asistencias, turnos asignados (y sus turnos),
 * permisos aprobados y feriados. Respeta el alcance de quien mira
 * (User::visibleTo y BelongsToBranchScope): un supervisor ve solo a su equipo.
 *
 * Un mes ya cerrado se guarda en caché CACHE_MINUTES; cualquier cambio en una
 * asistencia o un permiso de ese mes sube la versión (bump()) y la invalida.
 */
class AttendanceCalendarService
{
    public const PER_PAGE = 50;

    public const CACHE_MINUTES = 10;

    /**
     * Semáforo: [letra, tono Bootstrap, etiqueta]. El tono se repite en la
     * exportación a Excel (FILLS) y la letra permite leerlo impreso o con daltonismo.
     */
    public const STATES = [
        'present'    => ['P', 'success', 'Puntual'],
        'late'       => ['T', 'warning', 'Tardanza'],
        'incomplete' => ['I', 'warning', 'Sin salida'],
        'absent'     => ['F', 'danger', 'Falta'],
        'excused'    => ['J', 'primary', 'Justificado o permiso'],
        'half_day'   => ['M', 'info', 'Medio día'],
        'rest'       => ['D', 'secondary', 'Descanso o feriado'],
        'scheduled'  => ['–', 'light', 'Programado'],
    ];

    /** Relleno para Excel (RGB) por estado */
    public const FILLS = [
        'present'    => '198754',
        'late'       => 'FFC107',
        'incomplete' => 'FFE69C',
        'absent'     => 'DC3545',
        'excused'    => '0D6EFD',
        'half_day'   => '9EC5FE',
        'rest'       => 'CED4DA',
        'scheduled'  => 'FFFFFF',
    ];

    // =========================================================================
    // API PÚBLICA
    // =========================================================================

    /** Colaboradores del calendario (marcan asistencia y son visibles para quien mira) */
    public function employeesQuery(CalendarFilters $f): Builder
    {
        [$from, $to] = $f->range();

        return User::forTenant($f->tenantId)
            ->whereIn('users.role', User::CLOCKING_ROLES)
            ->when($f->viewer, fn ($q) => $q->visibleTo($f->viewer))
            ->when($f->branchId, fn ($q, $id) => $q->whereHas('branches', fn ($b) => $b->where('branches.id', $id)))
            ->when($f->zoneId, fn ($q, $id) => $q->whereHas('branches', fn ($b) => $b->where('branches.zone_id', $id)))
            ->when($f->departmentId, fn ($q, $id) => $q->where('users.department_id', $id))
            ->when($f->positionId, fn ($q, $id) => $q->where('users.position_id', $id))
            ->when($f->search, function ($q, $term) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
                $q->where(fn ($w) => $w->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('employee_code', 'like', $like)
                    ->orWhere('document_number', 'like', $like));
            })
            ->where(fn ($q) => $q->whereNull('hire_date')->orWhereDate('hire_date', '<=', $to))
            ->where(fn ($q) => $q->whereNull('termination_date')->orWhereDate('termination_date', '>=', $from));
    }

    /**
     * Página de la matriz: cada elemento es una fila (colaborador, celdas, totales).
     */
    public function paginate(CalendarFilters $f, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $page = $this->employeesQuery($f)
            ->orderBy('last_name')->orderBy('first_name')->orderBy('users.id')
            ->paginate($perPage, ['users.*'])
            ->withQueryString();

        $rows = $this->cached($f, 'page:' . $page->currentPage() . ':' . $perPage, fn () => $this->rows($page->getCollection(), $f));
        $page->setCollection(collect($rows));

        return $page;
    }

    /**
     * Todas las filas por bloques (exportación).
     *
     * @return \Generator<int, array>
     */
    public function each(CalendarFilters $f, int $chunk = 200): \Generator
    {
        $lastId = 0;

        do {
            $users = $this->employeesQuery($f)
                ->where('users.id', '>', $lastId)
                ->orderBy('users.id')
                ->limit($chunk)
                ->get(['users.*']);

            if ($users->isEmpty()) {
                break;
            }
            $lastId = (int) $users->last()->id;

            $rows = $this->rows($users, $f);
            usort($rows, fn ($a, $b) => [$a['last_name'], $a['first_name']] <=> [$b['last_name'], $b['first_name']]);

            yield from $rows;
        } while ($users->count() === $chunk);
    }

    /** Días del mes: [fecha Y-m-d => ['day' => 1..31, 'weekday' => 'L'..'D', 'weekend' => bool]] */
    public function days(CalendarFilters $f): array
    {
        [$from, $to] = $f->range();
        $letters = [1 => 'L', 2 => 'M', 3 => 'X', 4 => 'J', 5 => 'V', 6 => 'S', 7 => 'D'];
        $days = [];

        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addDay()) {
            $days[$d->toDateString()] = ['day' => $d->day, 'weekday' => $letters[$d->dayOfWeekIso], 'weekend' => $d->dayOfWeekIso >= 6];
        }

        return $days;
    }

    /** Invalida la caché del mes (lo llaman los eventos de Attendance y LeaveRequest) */
    public static function bump(?int $tenantId, string $date): void
    {
        // La vista global del Super Admin (sin empresa) también se invalida
        foreach (array_unique([$tenantId, null]) as $id) {
            $key = self::versionKey($id, substr($date, 0, 7));
            Cache::add($key, 0, now()->addDays(40));
            Cache::increment($key);
        }
    }

    /**
     * Filas de un grupo de colaboradores con un número fijo de consultas.
     *
     * @param  Collection<int, User>  $users
     * @return list<array>
     */
    public function rows(Collection $users, CalendarFilters $f): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        [$from, $to] = $f->range();
        $ids = $users->pluck('id')->all();
        $tenantIds = $users->pluck('tenant_id')->unique()->filter()->values()->all();

        $attendances = Attendance::forTenant($f->tenantId)
            ->whereIn('user_id', $ids)
            ->whereBetween('work_date', [$from, $to])
            ->orderBy('work_date')->orderBy('clock_in_at')->orderBy('id')
            ->get(['id', 'tenant_id', 'user_id', 'branch_id', 'work_date', 'status', 'clock_in_at', 'clock_out_at',
                'late_minutes', 'worked_hours', 'is_suspicious', 'justification_status'])
            ->groupBy(fn (Attendance $a) => $a->user_id . '|' . $a->work_date->toDateString());

        $assignments = EmployeeShift::forTenant($f->tenantId)
            ->whereIn('user_id', $ids)
            ->where('is_active', true)
            ->where('effective_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $from))
            ->with('shift')
            ->orderByDesc('effective_from')
            ->get()
            ->groupBy('user_id');

        $leaves = LeaveRequest::forTenant($f->tenantId)
            ->whereIn('user_id', $ids)
            ->where('status', 'approved')
            ->overlapping($from, $to)
            ->get(['id', 'user_id', 'type', 'start_date', 'end_date'])
            ->groupBy('user_id');

        $holidays = [];
        Holiday::withoutGlobalScopes()
            ->whereIn('tenant_id', $tenantIds)
            ->whereBetween('date', [$from, $to])
            ->get(['tenant_id', 'date', 'name'])
            ->each(function (Holiday $h) use (&$holidays) {
                $holidays[$h->tenant_id][$h->date->toDateString()] = $h->name ?: 'Feriado';
            });

        $today = $this->todayByTenant($tenantIds);

        return $users->map(fn (User $user) => $this->row(
            $user,
            $f,
            $attendances,
            $assignments->get($user->id, collect()),
            $leaves->get($user->id, collect()),
            $holidays[$user->tenant_id] ?? [],
            $today[$user->tenant_id] ?? CarbonImmutable::now(config('app.timezone'))->toDateString(),
        ))->values()->all();
    }

    // =========================================================================
    // CÁLCULO
    // =========================================================================

    private function row(User $user, CalendarFilters $f, Collection $attendances, Collection $assignments, Collection $leaves, array $holidays, string $today): array
    {
        $totals = ['absent' => 0, 'late' => 0, 'late_minutes' => 0, 'excused' => 0, 'worked_hours' => 0.0, 'suspicious' => 0];
        $cells = [];

        foreach ($this->days($f) as $day => $meta) {
            $records = $attendances->get($user->id . '|' . $day, collect());
            $cell = $this->cell($user, $day, $today, $records, $assignments, $leaves, $holidays);
            $cells[$day] = $cell;

            if ($cell === null) {
                continue;
            }
            $totals['absent']       += $cell['state'] === 'absent' ? 1 : 0;
            $totals['late']         += $cell['state'] === 'late' ? 1 : 0;
            $totals['excused']      += $cell['state'] === 'excused' ? 1 : 0;
            $totals['suspicious']   += $cell['suspicious'] ? 1 : 0;
            $totals['late_minutes'] += $cell['late_minutes'];
            $totals['worked_hours'] += $cell['worked_hours'];
        }

        $totals['worked_hours'] = round($totals['worked_hours'], 2);

        return [
            'user_id'       => $user->id,
            'tenant_id'     => $user->tenant_id,
            'employee_code' => $user->employee_code,
            'first_name'    => $user->first_name,
            'last_name'     => $user->last_name,
            'name'          => trim("{$user->first_name} {$user->last_name}"),
            'position'      => $user->position,
            'cells'         => $cells,
            'totals'        => $totals,
        ];
    }

    /**
     * Estado de un día. null = sin datos que mostrar (fuera de contrato o sin turno asignado).
     *
     * @param  Collection<int, Attendance>  $records
     */
    private function cell(User $user, string $day, string $today, Collection $records, Collection $assignments, Collection $leaves, array $holidays): ?array
    {
        $employed = (! $user->hire_date || $user->hire_date->toDateString() <= $day)
            && (! $user->termination_date || $user->termination_date->toDateString() >= $day);
        if (! $employed) {
            return null;
        }

        $leave = $leaves->first(fn (LeaveRequest $l) => $l->start_date->toDateString() <= $day && $l->end_date->toDateString() >= $day);

        // Con marcación: manda el estado guardado (el mismo que usan los reportes)
        $worked = $records->first(fn (Attendance $a) => $a->clock_in_at !== null);
        $record = $worked ?? $records->first();

        if ($record) {
            $state = match ($record->status) {
                'late'       => 'late',
                'incomplete' => 'incomplete',
                'absent'     => ($leave || $record->justification_status === 'approved') ? 'excused' : 'absent',
                'excused'    => 'excused',
                'half_day'   => 'half_day',
                'holiday', 'day_off' => 'rest',
                default      => (int) $record->late_minutes > 0 ? 'late' : 'present',
            };

            // Entró y no marcó salida en un día ya cerrado
            if ($worked && ! $worked->clock_out_at && $day < $today && in_array($state, ['present', 'late'], true)) {
                $state = 'incomplete';
            }

            $lateMinutes = (int) $records->sum(fn (Attendance $a) => max(0, (int) $a->late_minutes));

            return $this->make($state, [
                'attendance_id' => $record->id,
                'suspicious'    => $records->contains(fn (Attendance $a) => $a->is_suspicious),
                'clock_in'      => $worked?->clock_in_at?->format('H:i'),
                'clock_out'     => $records->last(fn (Attendance $a) => $a->clock_out_at !== null)?->clock_out_at?->format('H:i'),
                'late_minutes'  => $lateMinutes,
                'worked_hours'  => (float) $records->sum(fn (Attendance $a) => (float) $a->worked_hours),
                'note'          => $leave && $state === 'excused' ? $leave->type_label : null,
            ]);
        }

        if ($leave) {
            return $this->make('excused', ['note' => $leave->type_label]);
        }
        if (isset($holidays[$day])) {
            return $this->make('rest', ['note' => $holidays[$day]]);
        }

        $assignment = $assignments->first(fn (EmployeeShift $a) => $a->effective_from->toDateString() <= $day
            && (! $a->effective_until || $a->effective_until->toDateString() >= $day));
        if (! $assignment) {
            return null;
        }

        $scheduled = $assignment->worksOn(CarbonImmutable::parse($day));
        if (! $scheduled) {
            return $this->make('rest', ['note' => 'Sin turno ese día']);
        }

        // Hoy aún puede marcar; días futuros solo muestran el turno
        return $day >= $today
            ? $this->make('scheduled', ['note' => $assignment->shift?->name])
            : $this->make('absent', ['note' => 'Sin marcación']);
    }

    private function make(string $state, array $extra = []): array
    {
        [$letter, $tone, $label] = self::STATES[$state];

        return array_merge([
            'state'         => $state,
            'letter'        => $letter,
            'tone'          => $tone,
            'label'         => $label,
            'attendance_id' => null,
            'suspicious'    => false,
            'clock_in'      => null,
            'clock_out'     => null,
            'late_minutes'  => 0,
            'worked_hours'  => 0.0,
            'note'          => null,
        ], $extra);
    }

    /** @return array<int, string> fecha de hoy (Y-m-d) en la zona horaria de cada empresa */
    private function todayByTenant(array $tenantIds): array
    {
        return Tenant::whereIn('id', $tenantIds)->pluck('timezone', 'id')
            ->map(fn ($tz) => CarbonImmutable::now($tz ?: config('app.timezone'))->toDateString())
            ->all();
    }

    // =========================================================================
    // CACHÉ (solo meses cerrados)
    // =========================================================================

    private function cached(CalendarFilters $f, string $part, \Closure $build): array
    {
        [, $to] = $f->range();
        if ($to >= CarbonImmutable::now(tenant_timezone())->toDateString()) {
            return $build();
        }

        $version = (int) Cache::get(self::versionKey($f->tenantId, $f->month), 0);
        $key = 'attendance-calendar:' . sha1(json_encode([$f->toQuery(), $f->tenantId, $f->viewer?->id, $part, $version]));

        return Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), $build);
    }

    private static function versionKey(?int $tenantId, string $month): string
    {
        return 'attendance-calendar:v:' . ($tenantId ?? 'all') . ':' . $month;
    }
}
