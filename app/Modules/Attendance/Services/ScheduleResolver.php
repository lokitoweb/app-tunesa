<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Services\TenantContext;
use App\Modules\HR\Models\EmployeeScheduleDay;
use App\Modules\HR\Models\EmployeeShift;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Fuente única de "qué turno le toca a una persona en una fecha" (QA Ronda 2, HQ-01).
 *
 * Siempre, en toda empresa y agencia: si la persona tiene ese día programado en el
 * calendario (employee_schedule_days), manda esa fila: su turno en esa agencia, o día
 * libre si shift_id es NULL. Programar a alguien basta para que la app, la marcación,
 * las faltas y los reportes lo usen; quien no tiene el día programado sigue como antes.
 *
 * Días sin programar, con la bandera de empresa schedule_v2 encendida:
 *   - Asignación vigente que sí trabaja ese día; empate: vigencia más reciente, luego id.
 *   - Si ninguna trabaja: día libre, sin respaldo (para calcular no hay turno; la
 *     referencia de sede sigue siendo la vigente más reciente).
 *
 * Días sin programar con la bandera apagada (valor por defecto): reproduce exactamente
 * lo que hacían las ocho copias anteriores, según la estrategia que pide el llamador:
 *   - LEGACY_FIRST_WORKING (marcación, corrección manual, faltas, recordatorio de entrada,
 *     ScheduleGate, "Mis turnos" de la app): la primera vigente que trabaja en el orden
 *     recibido; para calcular, si ninguna trabaja, la primera vigente.
 *   - LEGACY_LATEST (calendario, mapa en vivo, reporte de horas): la primera vigente en el
 *     orden recibido (el llamador ordena por vigencia más reciente), trabaje o no ese día.
 *
 * ponytail: las dos estrategias legacy y la bandera se borran tras la conformidad de QA y dos
 * semanas con schedule_v2 encendida sin incidencias (docs/qa/BANDERAS.md).
 * La bandera se lee una vez por empresa y por instancia (los servicios no son singletons).
 */
class ScheduleResolver
{
    public const FLAG = 'schedule_v2';

    public const LEGACY_FIRST_WORKING = 'first_working';
    public const LEGACY_LATEST = 'latest';

    /** @var array<int, bool> */
    private array $enabled = [];

    /** @var array<string, EmployeeScheduleDay> filas por fecha leídas ("user|fecha") */
    private array $rows = [];

    /** @var array<int, array<int, array{0: string, 1: string}>> rangos ya leídos por persona */
    private array $loaded = [];

    /**
     * @param  Collection<int, EmployeeShift>  $assignments  de UNA persona: las vigentes en la fecha o un rango que las incluya
     * @param  null|callable(EmployeeShift): bool  $eligible  condición extra del llamador (p. ej. ventana del recordatorio):
     *                                                       legacy la aplica antes de elegir, como antes; v2 después de elegir
     */
    public function resolve(Collection $assignments, CarbonInterface|string $date, string $legacy, ?callable $eligible = null, ?int $userId = null, ?int $tenantId = null): DaySchedule
    {
        $day = $date instanceof CarbonInterface ? $date : CarbonImmutable::parse($date);
        $tenantId ??= $assignments->first()?->tenant_id;
        $userId ??= $assignments->first()?->user_id;

        // El día programado manda siempre (no depende de la bandera)
        $row = $tenantId && $userId ? $this->dayRow((int) $tenantId, (int) $userId, $day->toDateString()) : null;
        if ($row) {
            return $this->fromRow($row, $assignments, $day, $eligible);
        }
        if ($tenantId && $this->enabled((int) $tenantId)) {
            return $this->v2($assignments, $day, $eligible);
        }

        if ($legacy === self::LEGACY_LATEST) {
            $latest = $assignments->first(fn (EmployeeShift $a) => self::inRange($a, $day));

            return new DaySchedule($latest, $latest, (bool) $latest?->worksOn($day), $latest !== null);
        }

        // Mismo filtro que EmployeeShift::current(): no cambia nada para quien ya cargó con current()
        $current = $assignments->filter(fn (EmployeeShift $a) => self::inRange($a, $day));
        $working = ($eligible ? $current->filter($eligible) : $current)->first(fn (EmployeeShift $a) => $a->worksOn($day));
        $fallback = $working ?? $current->first();

        return new DaySchedule($fallback, $fallback, $working !== null, $current->isNotEmpty());
    }

    /** Versión de las banderas de una empresa en caché: attendance:flag la sube y las cachés que dependen de ella cambian de clave */
    public static function flagsVersionKey(int $tenantId): string
    {
        return "attendance-flags:{$tenantId}";
    }

    public function enabled(int $tenantId): bool
    {
        if (! isset($this->enabled[$tenantId])) {
            // La empresa de la request ya está cargada: sin consulta extra
            $current = app(TenantContext::class)->getOrNull();
            $this->enabled[$tenantId] = (bool) ($current?->id === $tenantId ? $current : Tenant::find($tenantId))
                ?->setting('flags.' . self::FLAG, false);
        }

        return $this->enabled[$tenantId];
    }

    /** Para quien ya cargó las empresas (calendario): evita una consulta por empresa */
    public function remember(Tenant $tenant): void
    {
        $this->enabled[$tenant->id] = (bool) $tenant->setting('flags.' . self::FLAG, false);
    }

    /**
     * Lee de una vez las filas por fecha de varias personas (calendario, reportes, procesos por lote):
     * una consulta por grupo, no por celda.
     */
    public function preload(int $tenantId, array $userIds, string $from, string $to): void
    {
        if (! $userIds) {
            return;
        }
        $rows = EmployeeScheduleDay::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->whereIn('user_id', $userIds)->whereBetween('work_date', [$from, $to])->with('shift')->get();
        foreach ($userIds as $userId) {
            $this->loaded[$userId][] = [$from, $to];
        }
        foreach ($rows as $row) {
            $this->rows[$row->user_id . '|' . $row->work_date->toDateString()] = $row;
        }
    }

    /** Personas con un horario programado ese día (para procesos que recorren asignaciones) */
    public function programmedUserIds(int $tenantId, string $date): array
    {
        return EmployeeScheduleDay::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->where('work_date', $date)->whereNotNull('shift_id')->pluck('user_id')->all();
    }

    /** Asignación semanal o rotativa vigente que trabaja ese día; empate: vigencia más reciente, luego id */
    public function working(Collection $assignments, CarbonInterface $day): ?EmployeeShift
    {
        return self::current($assignments, $day)->first(fn (EmployeeShift $a) => $a->worksOn($day));
    }

    private function v2(Collection $assignments, CarbonInterface $day, ?callable $eligible): DaySchedule
    {
        $current = self::current($assignments, $day);
        $working = $current->first(fn (EmployeeShift $a) => $a->worksOn($day));
        $assignment = $working && (! $eligible || $eligible($working)) ? $working : null;

        return new DaySchedule($assignment, $working ?? $current->first(), $assignment !== null, $current->isNotEmpty());
    }

    /** La fila del día manda: con turno, ese turno en esa agencia; sin turno, día libre */
    private function fromRow(EmployeeScheduleDay $row, Collection $assignments, CarbonInterface $day, ?callable $eligible): DaySchedule
    {
        if (! $row->shift_id) {
            return new DaySchedule(null, self::current($assignments, $day)->first(), false, true);
        }
        $programmed = $row->asAssignment();
        $assignment = ! $eligible || $eligible($programmed) ? $programmed : null;

        return new DaySchedule($assignment, $programmed, $assignment !== null, true);
    }

    private function dayRow(int $tenantId, int $userId, string $date): ?EmployeeScheduleDay
    {
        $covered = collect($this->loaded[$userId] ?? [])->contains(fn ($r) => $r[0] <= $date && $r[1] >= $date);
        if (! $covered) {
            $month = CarbonImmutable::parse($date);
            $this->preload($tenantId, [$userId], $month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString());
        }

        return $this->rows["{$userId}|{$date}"] ?? null;
    }

    private static function current(Collection $assignments, CarbonInterface $day): Collection
    {
        return $assignments
            ->filter(fn (EmployeeShift $a) => $a->is_active && self::inRange($a, $day))
            ->sort(fn (EmployeeShift $a, EmployeeShift $b) => [$b->effective_from->toDateString(), $b->id] <=> [$a->effective_from->toDateString(), $a->id]);
    }

    private static function inRange(EmployeeShift $a, CarbonInterface $day): bool
    {
        $d = $day->toDateString();

        return $a->effective_from->toDateString() <= $d && (! $a->effective_until || $a->effective_until->toDateString() >= $d);
    }
}
