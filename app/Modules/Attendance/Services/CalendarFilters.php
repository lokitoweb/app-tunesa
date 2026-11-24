<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Filtros del calendario con semáforo: mes (Y-m), sede, zona, área, cargo y búsqueda.
 * tenantId null = vista global del Super Admin. viewer: quien mira (alcance).
 * day (Y-m-d) reduce el rango a un día (vista diaria); shiftId deja solo a
 * quienes tienen ese turno asignado; userId, a una persona (su calendario).
 * from/to (Y-m-d) eligen un periodo propio (p. ej. la semana del tareo) en vez del mes entero.
 */
final class CalendarFilters
{
    /** Periodo propio más largo que se acepta (dos meses): la matriz tiene una columna por día */
    public const MAX_RANGE_DAYS = 62;

    public function __construct(
        public readonly ?int $tenantId,
        public readonly string $month,
        public readonly ?int $branchId = null,
        public readonly ?int $zoneId = null,
        public readonly ?int $departmentId = null,
        public readonly ?int $positionId = null,
        public readonly ?string $search = null,
        public readonly ?User $viewer = null,
        public readonly ?string $day = null,
        public readonly ?int $shiftId = null,
        public readonly ?int $userId = null,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
    ) {}

    public static function fromRequest(Request $request, ?int $tenantId, bool $daily = false, ?int $userId = null): self
    {
        $data = $request->validate([
            'month'         => ['nullable', 'date_format:Y-m'],
            'date'          => ['nullable', 'date_format:Y-m-d'],
            'from'          => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to'            => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
            'shift_id'      => ['nullable', 'integer'],
            'branch_id'     => ['nullable', 'integer'],
            'zone_id'       => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'position_id'   => ['nullable', 'integer'],
            'q'             => ['nullable', 'string', 'max:80'],
        ]);

        $int  = fn (string $key) => isset($data[$key]) ? (int) $data[$key] : null;
        $now  = CarbonImmutable::now(tenant_timezone());
        $day  = $daily ? ($data['date'] ?? $now->toDateString()) : null;
        $from = $daily ? null : ($data['from'] ?? null);
        $to   = $from ? $data['to'] : null;
        if ($from && CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['to' => 'Elige un periodo de hasta ' . self::MAX_RANGE_DAYS . ' días.']);
        }

        return new self(
            $tenantId,
            $day ? substr($day, 0, 7) : ($from ? substr($from, 0, 7) : ($data['month'] ?? $now->format('Y-m'))),
            $int('branch_id'),
            $int('zone_id'),
            $int('department_id'),
            $int('position_id'),
            $data['q'] ?? null,
            $request->user(),
            $day,
            $daily ? $int('shift_id') : null,
            $userId,
            $from,
            $to,
        );
    }

    /** @return array{0: string, 1: string} primer y último día del mes, el periodo elegido o el día de la vista diaria (Y-m-d) */
    public function range(): array
    {
        if ($this->day) {
            return [$this->day, $this->day];
        }
        if ($this->from && $this->to) {
            return [$this->from, $this->to];
        }

        $start = CarbonImmutable::createFromFormat('!Y-m', $this->month);

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }

    /**
     * Semanas de lunes a domingo del periodo para los subtotales del tareo, cortadas al inicio y al fin del periodo
     * y en el cambio de mes (la semana del 30/11 al 06/12 son dos: «30/11» en noviembre y «01–06/12» en diciembre).
     * Juntas cubren todos los días del periodo, una sola vez: ningún día queda fuera ni se cuenta dos veces.
     * El número es la semana del mes (la 1.ª puede ser de un día: noviembre de 2026 empieza en domingo).
     *
     * @return list<array{from: string, to: string, label: string}>
     */
    public function weeks(): array
    {
        [$from, $to] = $this->range();
        $weeks = [];
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $end->addDay()) {
            $end = collect([$d->addDays(7 - $d->dayOfWeekIso), $d->endOfMonth()->startOfDay(), CarbonImmutable::parse($to)])->min();
            $number = intdiv($d->day + $d->startOfMonth()->dayOfWeekIso - 2, 7) + 1;
            $weeks[] = [
                'from'  => $d->toDateString(),
                'to'    => $end->toDateString(),
                'label' => "Sem {$number} · " . ($d->isSameDay($end) ? $d->format('d/m') : $d->format('d') . '–' . $end->format('d/m')),
            ];
        }

        return $weeks;
    }

    /** Meses (Y-m) que toca el rango: la caché de meses cerrados se invalida por mes */
    public function months(): array
    {
        [$from, $to] = $this->range();
        $months = [];
        for ($m = CarbonImmutable::parse($from)->startOfMonth(); $m->toDateString() <= $to; $m = $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }

        return $months;
    }

    /** Título del periodo: «Noviembre de 2026» o «01/11/2026 – 07/11/2026» */
    public function periodLabel(): string
    {
        if (! $this->from) {
            return Str::ucfirst(CarbonImmutable::createFromFormat('!Y-m', $this->month)->locale('es')->isoFormat('MMMM [de] YYYY'));
        }

        return CarbonImmutable::parse($this->from)->format('d/m/Y') . ' – ' . CarbonImmutable::parse($this->to)->format('d/m/Y');
    }

    /** Acceso rápido del selector de periodo que coincide (today, week, month…; '' = personalizado u otro mes) */
    public function preset(): string
    {
        $now = CarbonImmutable::now(tenant_timezone());
        if (! $this->from) {
            return match ($this->month) {
                $now->format('Y-m')                         => 'month',
                $now->subMonthNoOverflow()->format('Y-m') => 'prev_month',
                default                                     => '',
            };
        }

        $today = $now->toDateString();

        return (string) array_search([$this->from, $this->to], [
            'today'        => [$today, $today],
            'yesterday'    => [$now->subDay()->toDateString(), $now->subDay()->toDateString()],
            'week'         => [$now->subDays(6)->toDateString(), $today],
            'last_30_days' => [$now->subDays(29)->toDateString(), $today],
        ], true);
    }

    public function previousMonth(): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->month)->subMonthNoOverflow()->format('Y-m');
    }

    public function nextMonth(): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->month)->addMonthNoOverflow()->format('Y-m');
    }

    public function previousDay(): string
    {
        return CarbonImmutable::parse($this->day)->subDay()->toDateString();
    }

    public function nextDay(): string
    {
        return CarbonImmutable::parse($this->day)->addDay()->toDateString();
    }

    /** Parámetros para reconstruir la URL (paginación, exportación, meses) */
    public function toQuery(array $override = []): array
    {
        return array_filter(array_merge([
            'month'         => $this->day || $this->from ? null : $this->month,
            'from'          => $this->from,
            'to'            => $this->to,
            'date'          => $this->day,
            'shift_id'      => $this->shiftId,
            'branch_id'     => $this->branchId,
            'zone_id'       => $this->zoneId,
            'department_id' => $this->departmentId,
            'position_id'   => $this->positionId,
            'q'             => $this->search,
        ], $override), fn ($v) => $v !== null && $v !== '');
    }
}
