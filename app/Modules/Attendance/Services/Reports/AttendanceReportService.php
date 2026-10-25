<?php

namespace App\Modules\Attendance\Services\Reports;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\ScheduleResolver;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Holiday;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\Shift;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Reporte de horas y faltas": por colaborador y por día.
 *
 * - Horas efectivas: salida − entrada − descanso. El descanso se descuenta
 *   solo si el refrigerio NO es pagado (TenantSetting efectivo `lunch_paid`).
 * - Tardanza: suma de attendances.late_minutes (la calcula la marcación).
 * - Horas extra: lo trabajado por encima de shifts.daily_hours (8 h sin turno).
 *   Se asignan al FINAL de la jornada y se parten en diurnas / nocturnas según
 *   la hora real en que caen (Perú: nocturno de 22:00 a 06:00, hora local).
 * - Horas nocturnas: total trabajado dentro de la franja 22:00–06:00.
 * - Faltas injustificadas: día laborable según el turno vigente (fijo o
 *   rotativo), que no es feriado, sin solicitud aprobada, sin marcación de
 *   entrada (o con estado 'absent' sin justificación aprobada). Solo cuentan
 *   días ya terminados (antes de hoy, hora de la empresa).
 * - Faltas justificadas: las mismas, pero con solicitud aprobada, estado
 *   'excused' o justificación aprobada.
 * - Salidas anticipadas: minutos entre la salida y el fin programado del turno.
 *
 * Las horas se guardan como "hora de pared" de la empresa (ver AttendanceService),
 * así que se leen sin conversión de zona: los cálculos son en minutos locales.
 *
 * Para exportaciones grandes, each() recorre colaboradores con chunkById y
 * carga marcaciones, turnos y solicitudes por bloque (sin N+1).
 */
class AttendanceReportService
{
    /** Franja nocturna (minutos desde medianoche, hora local) */
    public const NIGHT_START = 22 * 60;

    public const NIGHT_END = 6 * 60;

    public const DEFAULT_DAILY_HOURS = 8.0;

    public const STATUS_LABELS = [
        'worked'    => 'Trabajado',
        'absent'    => 'Falta injustificada',
        'justified' => 'Falta justificada',
    ];

    /** @var array<int, bool> refrigerio pagado por empresa */
    private array $lunchPaid = [];

    private ?ScheduleResolver $schedules = null;

    /** @var array<int, array<string, true>> feriados por empresa */
    private array $holidays = [];

    // =========================================================================
    // API PÚBLICA
    // =========================================================================

    /** Colaboradores incluidos en el reporte (marcan asistencia y son visibles para quien mira) */
    public function employeesQuery(ReportFilters $f): Builder
    {
        return User::forTenant($f->tenantId)
            ->whereIn('users.role', User::CLOCKING_ROLES)
            ->when($f->viewer, fn ($q) => $q->visibleTo($f->viewer))
            ->when($f->userId, fn ($q, $id) => $q->where('users.id', $id))
            ->when($f->branchId, fn ($q, $id) => $q->whereHas('branches', fn ($b) => $b->where('branches.id', $id)))
            ->when($f->search, function ($q, $term) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
                $q->where(fn ($w) => $w->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('employee_code', 'like', $like)
                    ->orWhere('document_number', 'like', $like));
            })
            // Excluye a quien ya no trabajaba en el periodo o aún no ingresaba
            ->where(fn ($q) => $q->whereNull('hire_date')->orWhereDate('hire_date', '<=', $f->to))
            ->where(fn ($q) => $q->whereNull('termination_date')->orWhereDate('termination_date', '>=', $f->from));
    }

    /** Página de resúmenes por colaborador (vista previa del panel) */
    public function paginate(ReportFilters $f, int $perPage = 25): LengthAwarePaginator
    {
        $page = $this->employeesQuery($f)
            ->orderBy('last_name')->orderBy('first_name')->orderBy('users.id')
            ->paginate($perPage)
            ->withQueryString();

        $reports = collect($this->forUsers($page->getCollection(), $f));
        $page->setCollection($reports->map(fn ($r) => array_diff_key($r, ['days' => true])));

        return $page;
    }

    /**
     * Recorre a todos los colaboradores del reporte por bloques.
     *
     * @return Generator<int, array> un reporte por colaborador (summary + days)
     */
    public function each(ReportFilters $f, int $chunk = 200): Generator
    {
        $lastId = 0;

        do {
            $users = $this->employeesQuery($f)
                ->where('users.id', '>', $lastId)
                ->orderBy('users.id')
                ->limit($chunk)
                ->get();

            if ($users->isEmpty()) {
                break;
            }

            $lastId = (int) $users->last()->id;

            $reports = $this->forUsers($users, $f);
            usort($reports, fn ($a, $b) => [$a['last_name'], $a['first_name']] <=> [$b['last_name'], $b['first_name']]);

            yield from $reports;
        } while ($users->count() === $chunk);
    }

    /** Totales del periodo para todos los colaboradores filtrados */
    public function totals(ReportFilters $f): array
    {
        $totals = self::emptySummary() + ['employees' => 0];

        foreach ($this->each($f) as $report) {
            $totals['employees']++;
            foreach (array_keys(self::emptySummary()) as $key) {
                $totals[$key] += $report['summary'][$key];
            }
        }

        return self::roundSummary($totals);
    }

    /**
     * Reporte de un grupo de colaboradores (misma consulta por bloque).
     *
     * @param  Collection<int, User>  $users
     * @return list<array>
     */
    public function forUsers(Collection $users, ReportFilters $f): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $ids = $users->pluck('id')->all();
        $this->loadTenantData($users->pluck('tenant_id')->unique()->filter()->all(), $f);

        $attendances = Attendance::forTenant($f->tenantId)
            ->whereIn('user_id', $ids)
            ->whereBetween('work_date', [$f->from, $f->to])
            ->with(['shift', 'branch:id,name'])
            ->orderBy('work_date')
            ->orderBy('clock_in_at')
            ->get()
            ->groupBy(fn (Attendance $a) => $a->user_id . '|' . $a->work_date->toDateString());

        $assignments = EmployeeShift::forTenant($f->tenantId)
            ->whereIn('user_id', $ids)
            ->where('is_active', true)
            ->where('effective_from', '<=', $f->to)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $f->from))
            ->with('shift')
            ->orderByDesc('effective_from')
            ->get()
            ->groupBy('user_id');

        $leaves = LeaveRequest::forTenant($f->tenantId)
            ->whereIn('user_id', $ids)
            ->where('status', 'approved')
            ->overlapping($f->from, $f->to)
            ->get(['id', 'user_id', 'type', 'start_date', 'end_date'])
            ->groupBy('user_id');

        $branchNames = $this->primaryBranches($ids);

        return $users->map(fn (User $user) => $this->forUser(
            $user,
            $f,
            $attendances,
            $assignments->get($user->id, collect()),
            $leaves->get($user->id, collect()),
            $branchNames[$user->id] ?? null,
        ))->values()->all();
    }

    // =========================================================================
    // CÁLCULO POR COLABORADOR Y DÍA
    // =========================================================================

    private function forUser(User $user, ReportFilters $f, Collection $attendances, Collection $assignments, Collection $leaves, ?string $branch): array
    {
        $tenantId  = (int) $user->tenant_id;
        $lunchPaid = $this->lunchPaid[$tenantId] ?? (bool) TenantSetting::DEFAULTS['lunch_paid'];
        $holidays  = $this->holidays[$tenantId] ?? [];
        $timezone  = $this->tenantTimezone($tenantId);
        $today     = Carbon::now($timezone)->toDateString();

        $summary = self::emptySummary();
        $days    = [];

        $date = CarbonImmutable::parse($f->from);
        $end  = CarbonImmutable::parse($f->to);

        for (; $date->lte($end); $date = $date->addDay()) {
            $day      = $date->toDateString();
            $records  = $attendances->get($user->id . '|' . $day, collect());
            $isHoliday = isset($holidays[$day]);
            $employed = (! $user->hire_date || $user->hire_date->toDateString() <= $day)
                && (! $user->termination_date || $user->termination_date->toDateString() >= $day);
            $resolved   = ($this->schedules ??= app(ScheduleResolver::class))->resolve($assignments, $date, ScheduleResolver::LEGACY_LATEST);
            $assignment = $resolved->reference;
            $scheduled  = $employed && ! $isHoliday && $resolved->works;
            $leave      = $leaves->first(fn (LeaveRequest $l) => $l->start_date->toDateString() <= $day && $l->end_date->toDateString() >= $day);

            $worked = $records->filter(fn (Attendance $a) => $a->clock_in_at !== null);

            if ($worked->isNotEmpty()) {
                $row = $this->workedDay($worked, $resolved->assignment?->shift, $lunchPaid);
                $row += ['date' => $day, 'status' => 'worked', 'scheduled' => (bool) $scheduled, 'holiday' => $isHoliday, 'note' => $isHoliday ? 'Feriado trabajado' : ''];

                $summary['days_worked']++;
                $summary['worked_minutes']         += $row['worked_minutes'];
                $summary['late_minutes']           += $row['late_minutes'];
                $summary['late_days']              += $row['late_minutes'] > 0 ? 1 : 0;
                $summary['early_minutes']          += $row['early_minutes'];
                $summary['early_days']             += $row['early_minutes'] > 0 ? 1 : 0;
                $summary['overtime_day_minutes']   += $row['overtime_day_minutes'];
                $summary['overtime_night_minutes'] += $row['overtime_night_minutes'];
                $summary['night_minutes']          += $row['night_minutes'];
                $summary['scheduled_days']         += $scheduled ? 1 : 0;
                $days[] = $row;

                continue;
            }

            $markedAbsent = $records->contains(fn (Attendance $a) => in_array($a->status, ['absent', 'excused'], true));

            // Solo días laborables (o con falta registrada), que no sean feriado, ya cerrados
            if ($isHoliday || ! $employed || (! $scheduled && ! $markedAbsent) || $day >= $today) {
                $summary['scheduled_days'] += ($scheduled && $day < $today) ? 1 : 0;

                continue;
            }

            $summary['scheduled_days'] += $scheduled ? 1 : 0;

            $justifiedRecord = $records->first(fn (Attendance $a) => $a->status === 'excused' || $a->justification_status === 'approved');
            $justified = $leave !== null || $justifiedRecord !== null;

            $note = match (true) {
                $leave !== null           => $leave->type_label . ' aprobada',
                $justifiedRecord !== null => $justifiedRecord->status === 'excused' ? 'Justificado' : 'Justificación aprobada',
                default                   => 'Sin marcación',
            };

            $summary[$justified ? 'absences_justified' : 'absences_unjustified']++;

            $days[] = self::emptyDay() + [
                'date'      => $day,
                'status'    => $justified ? 'justified' : 'absent',
                'scheduled' => (bool) $scheduled,
                'holiday'   => false,
                'note'      => $note,
                'branch'    => $records->first()?->branch?->name,
                'shift'     => $assignment?->shift?->name,
            ];
        }

        return [
            'user_id'         => $user->id,
            'tenant_id'       => $tenantId,
            'employee_code'   => $user->employee_code,
            'document_number' => $user->document_number,
            'first_name'      => $user->first_name,
            'last_name'       => $user->last_name,
            'name'            => trim("{$user->first_name} {$user->last_name}"),
            'position'        => $user->position,
            'branch'          => $branch,
            'lunch_paid'      => $lunchPaid,
            'summary'         => self::roundSummary($summary),
            'days'            => $days,
        ];
    }

    /**
     * Una o más marcaciones del mismo día (p. ej. dos sedes): se suman.
     *
     * @param  Collection<int, Attendance>  $records
     */
    private function workedDay(Collection $records, ?Shift $assignedShift, bool $lunchPaid): array
    {
        $row = self::emptyDay();
        $first = $records->first();
        $last  = $records->last();

        foreach ($records as $attendance) {
            $calc = $this->calculate($attendance, $attendance->shift ?? $assignedShift, $lunchPaid);
            foreach ($calc as $key => $value) {
                if (is_int($value)) {
                    $row[$key] += $value;
                }
            }
        }

        $row['clock_in']  = hora(self::wallClock($first->clock_in_at));
        $row['clock_out'] = $last->clock_out_at ? hora(self::wallClock($last->clock_out_at)) : null;
        $row['branch']    = $first->branch?->name;
        $row['shift']     = ($first->shift ?? $assignedShift)?->name;
        $row['open']      = $records->contains(fn (Attendance $a) => $a->clock_out_at === null);

        return $row;
    }

    /**
     * Cálculo puro de una marcación (público para pruebas unitarias).
     *
     * @return array{worked_minutes:int, break_minutes:int, night_minutes:int, overtime_day_minutes:int, overtime_night_minutes:int, late_minutes:int, early_minutes:int}
     */
    public function calculate(Attendance $attendance, ?Shift $shift, bool $lunchPaid): array
    {
        $shift = $shift?->forDate($attendance->work_date);
        $result = [
            'worked_minutes'         => 0,
            'break_minutes'          => 0,
            'night_minutes'          => 0,
            'overtime_day_minutes'   => 0,
            'overtime_night_minutes' => 0,
            'late_minutes'           => max(0, (int) $attendance->late_minutes),
            'early_minutes'          => 0,
        ];

        $in  = self::wallClock($attendance->clock_in_at);
        $out = self::wallClock($attendance->clock_out_at);

        // Jornada abierta o incompleta: solo cuenta la tardanza
        if (! $in || ! $out || $out->lte($in)) {
            return $result;
        }

        $a = $in->getTimestamp();
        $b = $out->getTimestamp();
        $intervals = [[$a, $b]];

        // Descanso no pagado: se resta del tramo trabajado donde ocurrió
        $breakSeconds = 0;
        if (! $lunchPaid) {
            [$intervals, $breakSeconds] = $this->subtractBreak($intervals, $attendance, $a, $b);
        }

        $workedSeconds = array_sum(array_map(fn ($i) => $i[1] - $i[0], $intervals));
        $dailyHours    = $shift && (float) $shift->daily_hours > 0 ? (float) $shift->daily_hours : self::DEFAULT_DAILY_HOURS;
        $overtime      = max(0, $workedSeconds - (int) round($dailyHours * 3600));

        // Horas extra = cola de la jornada: se toman desde la salida hacia atrás
        $otNight = 0;
        $remaining = $overtime;
        foreach (array_reverse($intervals) as [$s, $e]) {
            if ($remaining <= 0) {
                break;
            }
            $take   = min($remaining, $e - $s);
            $otNight += self::nightOverlap($e - $take, $e);
            $remaining -= $take;
        }

        $result['worked_minutes']         = self::minutes($workedSeconds);
        $result['break_minutes']          = self::minutes($breakSeconds);
        $result['night_minutes']          = self::minutes(array_sum(array_map(fn ($i) => self::nightOverlap($i[0], $i[1]), $intervals)));
        $result['overtime_night_minutes'] = self::minutes($otNight);
        $result['overtime_day_minutes']   = self::minutes($overtime) - $result['overtime_night_minutes'];

        if ($shift && $shift->end_time) {
            $scheduledEnd = $this->scheduledEnd($attendance, $shift);
            if ($scheduledEnd && $b < $scheduledEnd->getTimestamp()) {
                $result['early_minutes'] = self::minutes($scheduledEnd->getTimestamp() - $b);
            }
        }

        return $result;
    }

    /**
     * Quita el descanso de los tramos. Si se conocen inicio y fin se resta ese
     * intervalo; si solo hay break_minutes, se ubica a mitad de la jornada.
     *
     * @return array{0: list<array{0:int,1:int}>, 1:int}
     */
    private function subtractBreak(array $intervals, Attendance $attendance, int $a, int $b): array
    {
        $bs = self::wallClock($attendance->break_start_at)?->getTimestamp();
        $be = self::wallClock($attendance->break_end_at)?->getTimestamp();

        if ($bs !== null && $be !== null && $be > $bs) {
            $bs = max($a, $bs);
            $be = min($b, $be);
        } else {
            $minutes = (int) ($attendance->break_minutes ?? 0);
            if ($minutes <= 0) {
                return [$intervals, 0];
            }
            $len = min($minutes * 60, $b - $a);
            $bs  = intdiv($a + $b, 2) - intdiv($len, 2);
            $be  = $bs + $len;
        }

        if ($be <= $bs) {
            return [$intervals, 0];
        }

        $out = [];
        foreach ($intervals as [$s, $e]) {
            if ($be <= $s || $bs >= $e) {
                $out[] = [$s, $e];
                continue;
            }
            if ($bs > $s) {
                $out[] = [$s, $bs];
            }
            if ($be < $e) {
                $out[] = [$be, $e];
            }
        }

        return [$out, $be - $bs];
    }

    /** Fin programado del turno para la jornada (día siguiente si cruza medianoche) */
    private function scheduledEnd(Attendance $attendance, Shift $shift): ?CarbonImmutable
    {
        $date = $attendance->work_date?->toDateString();
        if (! $date) {
            return null;
        }

        $start = CarbonImmutable::parse("{$date} {$shift->start_time}", 'UTC');
        $end   = CarbonImmutable::parse("{$date} {$shift->end_time}", 'UTC');

        if ($shift->crosses_midnight || $end->lte($start)) {
            $end = $end->addDay();
        }

        return $end;
    }

    /** Segundos de [a, b) que caen en la franja nocturna 22:00–06:00 */
    public static function nightOverlap(int $a, int $b): int
    {
        if ($b <= $a) {
            return 0;
        }

        $total = 0;
        // Cada franja empieza a las 22:00 de un día y termina a las 06:00 del siguiente
        $day = intdiv($a, 86400) * 86400 - 86400;
        if ($a < 0) {
            $day -= 86400;
        }

        for (; $day < $b; $day += 86400) {
            $ws = $day + self::NIGHT_START * 60;
            $we = $day + 86400 + self::NIGHT_END * 60;
            $total += max(0, min($b, $we) - max($a, $ws));
        }

        return $total;
    }

    /**
     * Hora de pared de la empresa como instante UTC "ingenuo": así las
     * restas y la franja nocturna se hacen en minutos locales sin DST.
     */
    public static function wallClock(?\DateTimeInterface $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::createFromFormat('Y-m-d H:i:s', $value->format('Y-m-d H:i:s'), 'UTC') : null;
    }

    private static function minutes(int $seconds): int
    {
        return (int) round($seconds / 60);
    }

    // =========================================================================
    // DATOS POR EMPRESA
    // =========================================================================

    /** Refrigerio pagado y feriados de las empresas del bloque (una vez por empresa) */
    private function loadTenantData(array $tenantIds, ReportFilters $f): void
    {
        $missing = array_diff($tenantIds, array_keys($this->lunchPaid));

        if ($missing) {
            $tenants = Tenant::whereIn('id', $missing)->get()->keyBy('id');
            foreach ($missing as $id) {
                $this->lunchPaid[$id] = (bool) TenantSetting::forTenant($tenants->get($id))->lunch_paid;
                $this->timezones[$id] = $tenants->get($id)?->timezone ?: config('app.timezone');
            }
        }

        $key = $f->from . '|' . $f->to;
        $needHolidays = array_filter($tenantIds, fn ($id) => ! isset($this->holidaysLoaded[$id][$key]));

        if ($needHolidays) {
            Holiday::withoutGlobalScopes()
                ->whereIn('tenant_id', $needHolidays)
                ->whereBetween('date', [$f->from, $f->to])
                ->get(['tenant_id', 'date'])
                ->each(function (Holiday $h) {
                    $this->holidays[$h->tenant_id][$h->date->toDateString()] = true;
                });

            foreach ($needHolidays as $id) {
                $this->holidaysLoaded[$id][$key] = true;
            }
        }
    }

    /** @var array<int, string> */
    private array $timezones = [];

    /** @var array<int, array<string, true>> */
    private array $holidaysLoaded = [];

    private function tenantTimezone(int $tenantId): string
    {
        return $this->timezones[$tenantId] ?? config('app.timezone');
    }

    /** Sucursal principal (o la primera) de cada colaborador */
    private function primaryBranches(array $userIds): array
    {
        return DB::table('employee_branch')
            ->join('branches', 'branches.id', '=', 'employee_branch.branch_id')
            ->whereIn('employee_branch.user_id', $userIds)
            ->orderByDesc('employee_branch.is_primary')
            ->orderBy('branches.name')
            ->get(['employee_branch.user_id', 'branches.name'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->first()->name)
            ->all();
    }

    // =========================================================================
    // ESTRUCTURAS
    // =========================================================================

    public static function emptySummary(): array
    {
        return [
            'days_worked'            => 0,
            'scheduled_days'         => 0,
            'worked_minutes'         => 0,
            'late_minutes'           => 0,
            'late_days'              => 0,
            'early_minutes'          => 0,
            'early_days'             => 0,
            'overtime_day_minutes'   => 0,
            'overtime_night_minutes' => 0,
            'night_minutes'          => 0,
            'absences_unjustified'   => 0,
            'absences_justified'     => 0,
        ];
    }

    /** Agrega las horas (2 decimales) a partir de los minutos */
    public static function roundSummary(array $summary): array
    {
        foreach (['worked', 'overtime_day', 'overtime_night', 'night'] as $key) {
            $summary["{$key}_hours"] = round($summary["{$key}_minutes"] / 60, 2);
        }

        return $summary;
    }

    private static function emptyDay(): array
    {
        return [
            'clock_in'               => null,
            'clock_out'              => null,
            'branch'                 => null,
            'shift'                  => null,
            'open'                   => false,
            'worked_minutes'         => 0,
            'break_minutes'          => 0,
            'night_minutes'          => 0,
            'overtime_day_minutes'   => 0,
            'overtime_night_minutes' => 0,
            'late_minutes'           => 0,
            'early_minutes'          => 0,
        ];
    }
}
