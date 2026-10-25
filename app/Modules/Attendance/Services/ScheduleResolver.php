<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Services\TenantContext;
use App\Modules\HR\Models\EmployeeShift;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Fuente única de "qué turno le toca a una persona en una fecha" (QA Ronda 2, HQ-01).
 *
 * Con la bandera de empresa schedule_v2 encendida (tenants.settings.flags.schedule_v2):
 *   1. Asignación vigente que sí trabaja ese día; empate: vigencia más reciente, luego id.
 *   2. Si ninguna trabaja: día libre, sin respaldo (para calcular no hay turno; la
 *      referencia de sede sigue siendo la vigente más reciente).
 *
 * Con la bandera apagada (valor por defecto) reproduce exactamente lo que hacían las
 * ocho copias anteriores, según la estrategia que pide el llamador:
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

    /**
     * @param  Collection<int, EmployeeShift>  $assignments  de UNA persona: las vigentes en la fecha o un rango que las incluya
     * @param  null|callable(EmployeeShift): bool  $eligible  condición extra del llamador (p. ej. ventana del recordatorio):
     *                                                       legacy la aplica antes de elegir, como antes; v2 después de elegir
     */
    public function resolve(Collection $assignments, CarbonInterface|string $date, string $legacy, ?callable $eligible = null): DaySchedule
    {
        $day = $date instanceof CarbonInterface ? $date : CarbonImmutable::parse($date);
        $tenantId = $assignments->first()?->tenant_id;

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

    private function v2(Collection $assignments, CarbonInterface $day, ?callable $eligible): DaySchedule
    {
        $current = $assignments
            ->filter(fn (EmployeeShift $a) => $a->is_active && self::inRange($a, $day))
            ->sort(fn (EmployeeShift $a, EmployeeShift $b) => [$b->effective_from->toDateString(), $b->id] <=> [$a->effective_from->toDateString(), $a->id]);

        $working = $current->first(fn (EmployeeShift $a) => $a->worksOn($day));
        $assignment = $working && (! $eligible || $eligible($working)) ? $working : null;

        return new DaySchedule($assignment, $working ?? $current->first(), $assignment !== null, $current->isNotEmpty());
    }

    private static function inRange(EmployeeShift $a, CarbonInterface $day): bool
    {
        $d = $day->toDateString();

        return $a->effective_from->toDateString() <= $d && (! $a->effective_until || $a->effective_until->toDateString() >= $d);
    }
}
