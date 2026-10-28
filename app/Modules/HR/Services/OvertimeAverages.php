<?php

namespace App\Modules\HR\Services;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;

/**
 * Promedio de horas extras para las bases de la liquidación, desde la asistencia (solo horas
 * extra aprobadas). Valor hora = remuneración / 30 / 8; cada día las 2 primeras horas al 25 % y
 * el resto al 35 %. Solo cuentan si son regulares: al menos 3 meses con horas extra en el periodo.
 *
 *  - CTS y vacaciones: los 6 meses calendario previos al cese (incluye el del cese si es fin de mes).
 *  - Gratificación: los meses del semestre del cese, promediados entre esos meses.
 */
class OvertimeAverages
{
    public const REGULAR_MONTHS = 3;

    /** @return array{cts: float, vacation: float, gratification: float, months: array<string, float>} */
    public function for(User $user, CarbonImmutable $end, float $salary): array
    {
        $last = $end->isLastOfMonth() ? $end : $end->startOfMonth()->subDay();
        $from = $last->startOfMonth()->subMonths(5);
        $hourly = $salary / 30 / 8;

        $payable = Attendance::payableOvertimeSql();
        $months = array_fill_keys(array_map(fn ($i) => $from->addMonths($i)->format('Y-m'), range(0, 5)), 0.0);
        Attendance::withoutGlobalScopes()->where('user_id', $user->id)
            ->whereBetween('work_date', [$from->toDateString(), $last->toDateString()])
            ->selectRaw("work_date, {$payable} as hours")->get()
            ->each(function ($row) use (&$months, $hourly) {
                $h = (float) $row->hours;
                $key = CarbonImmutable::parse($row->work_date)->format('Y-m');
                if ($h > 0 && isset($months[$key])) {
                    $months[$key] += $hourly * (min($h, 2) * 1.25 + max($h - 2, 0) * 1.35);
                }
            });

        $average = function (array $values, int $divisor) {
            $regular = count(array_filter($values, fn ($v) => $v > 0)) >= self::REGULAR_MONTHS;

            return $regular && $divisor > 0 ? round(array_sum($values) / $divisor, 2) : 0.0;
        };
        $semesterStart = ($last->month <= 6 ? $last->startOfYear() : $last->setDate($last->year, 7, 1))->format('Y-m');
        $semester = array_filter($months, fn ($k) => $k >= $semesterStart, ARRAY_FILTER_USE_KEY);
        $six = $average($months, 6);

        return ['cts' => $six, 'vacation' => $six, 'gratification' => $average($semester, count($semester)), 'months' => array_map(fn ($v) => round($v, 2), $months)];
    }
}
