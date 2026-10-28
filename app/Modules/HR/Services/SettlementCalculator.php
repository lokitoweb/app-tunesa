<?php

namespace App\Modules\HR\Services;

use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\LaborRegime;
use App\Modules\HR\Models\PensionRate;
use Carbon\CarbonImmutable;

/**
 * Liquidación de beneficios sociales al cese, con los parámetros del régimen de la persona
 * (LaborRegime) y su aporte de pensión (PensionRate). Un parámetro vacío en el régimen deja ese
 * concepto "sin parámetro" en vez de inventarlo.
 *
 * Cada beneficio tiene su base: fijo (básico + asignación familiar + otros conceptos fijos) más
 * el promedio de horas extras de ese beneficio; la CTS suma además 1/6 de la gratificación.
 *  - Vacaciones pendientes: días ganados y no gozados × base de vacaciones / 30.
 *  - Vacaciones truncas: base × (días de vacaciones/30) por meses y días desde el último aniversario.
 *  - Gratificación trunca: base × (gratificaciones al año/2) / 6 por mes calendario completo del
 *    semestre (ene-jun, jul-dic) + la bonificación extraordinaria (%).
 *  - CTS trunca: base CTS × CTS al año por meses y días desde el último periodo (1 may, 1 nov).
 *  - Indemnización (solo si se marca): días por año de servicio × base CTS / 30, con tope.
 * Aporte de pensión: solo sobre las vacaciones (la CTS, la gratificación con su bonificación y la
 * indemnización no aportan).
 *
 * @phpstan-type Input array{termination_date: string, salary: float, family_allowance: float, other_computable: float,
 *   ot_cts: float, ot_vacation: float, ot_gratification: float, pending_days: int, indemnity: bool,
 *   other_incomes: list<array{label: string, amount: float}>, other_discounts: list<array{label: string, amount: float}>}
 */
class SettlementCalculator
{
    /** @param  Input  $in */
    public function calculate(User $user, ?LaborRegime $regime, ?PensionRate $pension, array $in): array
    {
        $hire = CarbonImmutable::parse($user->hire_date->toDateString());
        $end = CarbonImmutable::parse($in['termination_date']);
        $money = fn (float $v) => round($v, 2);
        $param = fn (string $p) => $regime?->{$p};
        $warnings = $regime ? [] : ['La persona no tiene régimen laboral en su ficha: solo se calculan las vacaciones pendientes y los otros conceptos.'];

        // Bases por beneficio
        $fixed = $in['salary'] + $in['family_allowance'] + $in['other_computable'];
        $gratifications = $param('gratifications_per_year');
        $gratBase = $fixed + $in['ot_gratification'];
        $gratification = $gratifications === null ? null : $gratBase * $gratifications / 2;
        $sixth = ($gratification ?? 0) / 6;
        $vacBase = $fixed + $in['ot_vacation'];
        $ctsBase = $fixed + $in['ot_cts'] + $sixth;
        $component = fn (string $label, float $v) => ['label' => $label, 'amount' => $money($v)];
        $common = array_values(array_filter([
            $component('Remuneración básica', $in['salary']),
            $in['family_allowance'] > 0 ? $component('Asignación familiar', $in['family_allowance']) : null,
            $in['other_computable'] > 0 ? $component('Otros conceptos fijos', $in['other_computable']) : null,
        ]));
        $bases = [
            ['key' => 'cts', 'label' => 'CTS', 'total' => $money($ctsBase), 'components' => $this->ctsComponents($in, $sixth)],
            ['key' => 'vacation', 'label' => 'Vacaciones', 'total' => $money($vacBase), 'components' => [...$common, $component('Promedio de horas extras', $in['ot_vacation'])]],
            ['key' => 'gratification', 'label' => 'Gratificación', 'total' => $money($gratBase), 'components' => [...$common, $component('Promedio de horas extras', $in['ot_gratification'])]],
        ];

        $lines = [];
        $line = fn (string $key, string $label, string $basis, ?float $amount, bool $pension = false) => ['key' => $key, 'label' => $label, 'basis' => $basis,
            'amount' => $amount === null ? null : $money($amount), 'pension' => $pension];

        // I. CTS trunca
        $cts = $param('cts_per_year');
        if ($cts === null) {
            $lines[] = $this->missing('cts', 'CTS trunca', 'CTS al año');
        } else {
            $period = match (true) {
                $end->month >= 11 => $end->setDate($end->year, 11, 1),
                $end->month >= 5  => $end->setDate($end->year, 5, 1),
                default           => $end->setDate($end->year - 1, 11, 1),
            };
            $start = $hire->max($period);
            [$m, $d] = $this->span($start, $end);
            $lines[] = $line('cts', 'CTS trunca', "Del {$start->format('d/m/Y')} al {$end->format('d/m/Y')}: " . $this->fmt($ctsBase) . " × {$cts} / 12 × {$m} mes(es) + / 360 × {$d} día(s)",
                $ctsBase * $cts * ($m / 12 + $d / 360));
        }

        // II. Vacaciones: los días del régimen; en el general sin días propios, los de la empresa (como el saldo)
        $vacationDays = $param('vacation_days')
            ?? ($regime?->code === 'general' ? (int) TenantSetting::forTenant($user->tenant)->vacation_days_per_year : null);
        if ($in['pending_days'] > 0) {
            $lines[] = $line('vacation_pending', 'Vacaciones pendientes (no gozadas)', "{$in['pending_days']} día(s) × " . $this->fmt($vacBase) . ' / 30',
                $in['pending_days'] * $vacBase / 30, true);
        }
        $years = (int) $hire->diffInYears($end->addDay());
        $anniversary = $hire->addYears($years);
        [$m, $d] = $this->span($anniversary, $end);
        $lines[] = $vacationDays === null
            ? $this->missing('vacation_truncated', 'Vacaciones truncas', 'días de vacaciones')
            : $line('vacation_truncated', 'Vacaciones truncas', "Desde el {$anniversary->format('d/m/Y')}: " . $this->fmt($vacBase) . " × {$vacationDays}/30 / 12 × {$m} mes(es) + / 360 × {$d} día(s)",
                $vacBase * $vacationDays / 30 * ($m / 12 + $d / 360), true);

        // III. Gratificación trunca y bonificación extraordinaria
        if ($gratification === null) {
            $lines[] = $this->missing('gratification', 'Gratificación trunca', 'gratificaciones al año');
        } else {
            $semester = $end->month <= 6 ? $end->startOfYear() : $end->setDate($end->year, 7, 1);
            $months = $this->fullMonths($hire->max($semester), $end);
            $amount = $gratification / 6 * $months;
            $pct = (float) ($param('gratification_bonus_pct') ?? 0);
            $lines[] = $line('gratification', 'Gratificación trunca', "Semestre desde el {$semester->format('d/m/Y')}: " . $this->fmt($gratification) . " / 6 × {$months} mes(es) completo(s)", $amount);
            $lines[] = $line('gratification_bonus', 'Bonificación extraordinaria', "{$pct} % de la gratificación", $amount * $pct / 100);
        }

        // Indemnización por despido
        [$y, $sm, $sd] = $this->service($hire, $end);
        if ($in['indemnity']) {
            $perYear = $param('indemnity_days_per_year');
            if ($perYear === null) {
                $lines[] = $this->missing('indemnity', 'Indemnización por despido', 'días de indemnización por año');
            } else {
                $days = $perYear * ($y + $sm / 12 + $sd / 360);
                $cap = $param('indemnity_cap_days');
                $paid = min($days, $cap ?? $days);
                $lines[] = $line('indemnity', 'Indemnización por despido', round($paid, 2) . ' día(s) × ' . $this->fmt($ctsBase) . ' / 30' . ($cap !== null && $days > $cap ? " (tope {$cap})" : ''),
                    $paid * $ctsBase / 30);
            }
        }

        // IV. Otros ingresos
        foreach ($in['other_incomes'] as $i => $o) {
            $lines[] = $line("other_income_{$i}", $o['label'], 'Otro ingreso', $o['amount']);
        }

        $gross = array_sum(array_map(fn ($l) => $l['amount'] ?? 0, $lines));

        // V. Descuentos de ley y VI. otros descuentos
        $deductions = [];
        $affected = array_sum(array_map(fn ($l) => $l['pension'] ? ($l['amount'] ?? 0) : 0, $lines));
        if ($pension && $affected > 0) {
            foreach (['contribution_pct' => $pension->system === 'onp' ? 'Aporte ONP' : 'Aporte al fondo', 'insurance_pct' => 'Prima de seguro', 'commission_pct' => 'Comisión'] as $field => $label) {
                if ($pension->{$field} > 0) {
                    $deductions[] = ['key' => $field, 'label' => "{$pension->name} · {$label}", 'basis' => "{$pension->{$field}} % de " . $this->fmt($affected), 'amount' => $money($affected * $pension->{$field} / 100)];
                }
            }
        } elseif (! $pension && $affected > 0) {
            $warnings[] = 'Sin sistema de pensión elegido: no se descuenta aporte de las vacaciones.';
        }
        foreach ($in['other_discounts'] as $i => $o) {
            $deductions[] = ['key' => "other_discount_{$i}", 'label' => $o['label'], 'basis' => 'Otro descuento', 'amount' => $money($o['amount'])];
        }
        $totalDeductions = array_sum(array_column($deductions, 'amount'));

        return [
            'bases'      => $bases,
            'lines'      => $lines,
            'deductions' => $deductions,
            'gross'      => $money($gross),
            'deducted'   => $money($totalDeductions),
            'total'      => $money($gross - $totalDeductions),
            'service'    => "{$y} año(s), {$sm} mes(es) y {$sd} día(s)",
            'pension'    => $pension?->only(['id', 'name', 'system']),
            'warnings'   => $warnings,
        ];
    }

    /**
     * Depósito semestral de CTS (mayo / noviembre) con la misma base que la CTS de la liquidación.
     * Null si el régimen no tiene CTS o la persona no completó un mes en el semestre (no corresponde).
     *
     * @param  array{salary: float, family_allowance: float, other_computable: float, ot_cts: float}  $in
     * @return array{months: int, days: int, from: string, to: string, components: list<array>, base: float, amount: float, basis: string}|null
     */
    public function ctsDeposit(User $user, ?LaborRegime $regime, CarbonImmutable $start, CarbonImmutable $end, array $in): ?array
    {
        $cts = $regime?->cts_per_year;
        if (! $cts || ! $user->hire_date) {
            return null;
        }
        $from = CarbonImmutable::parse($user->hire_date->toDateString())->max($start);
        $to = $user->termination_date ? CarbonImmutable::parse($user->termination_date->toDateString())->min($end) : $end;
        [$m, $d] = $this->span($from, $to);
        if ($m < 1) {
            return null;
        }
        $fixed = $in['salary'] + $in['family_allowance'] + $in['other_computable'];
        $sixth = ($regime->gratifications_per_year ?? 0) * ($fixed + ($in['ot_gratification'] ?? 0)) / 2 / 6;
        $base = $fixed + $in['ot_cts'] + $sixth;

        return [
            'months' => $m, 'days' => $d, 'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'components' => $this->ctsComponents($in, $sixth), 'base' => round($base, 2), 'cts_per_year' => $cts,
            'amount' => round($base * $cts * ($m / 12 + $d / 360), 2),
            'basis' => $this->fmt($base) . " × {$cts} / 12 × {$m} mes(es) + / 360 × {$d} día(s)",
        ];
    }

    /** Partes de la base de CTS (las mismas en la liquidación y en el depósito semestral) */
    private function ctsComponents(array $in, float $sixth): array
    {
        $part = fn (string $label, float $v) => ['label' => $label, 'amount' => round($v, 2)];

        return array_values(array_filter([
            $part('Remuneración básica', $in['salary']),
            $in['family_allowance'] > 0 ? $part('Asignación familiar', $in['family_allowance']) : null,
            $in['other_computable'] > 0 ? $part('Otros conceptos fijos', $in['other_computable']) : null,
            $part('Promedio de horas extras', $in['ot_cts']),
            $part('1/6 de la gratificación', $sixth),
        ]));
    }

    /** Meses y días de start a end, contando ambos días */
    private function span(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $until = $end->addDay();
        if ($start->gte($until)) {
            return [0, 0];
        }
        $months = (int) $start->diffInMonths($until);

        return [$months, (int) $start->addMonths($months)->diffInDays($until)];
    }

    private function service(CarbonImmutable $hire, CarbonImmutable $end): array
    {
        [$months, $days] = $this->span($hire, $end);

        return [intdiv($months, 12), $months % 12, $days];
    }

    /** Meses calendario completos entre start y end (el de ingreso a medias y el del cese a medias no cuentan) */
    private function fullMonths(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $first = $start->day === 1 ? $start : $start->addMonthNoOverflow()->startOfMonth();
        $last = $end->isLastOfMonth() ? $end : $end->startOfMonth()->subDay();

        return $first->gt($last) ? 0 : ($last->year - $first->year) * 12 + $last->month - $first->month + 1;
    }

    private function missing(string $key, string $label, string $param): array
    {
        return ['key' => $key, 'label' => $label, 'basis' => "Sin parámetro: define «{$param}» en el régimen", 'amount' => null, 'pension' => false];
    }

    private function fmt(float $v): string
    {
        return number_format($v, 2, '.', ',');
    }
}
