<?php

namespace App\Modules\HR\Services;

use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\LaborRegime;
use Carbon\CarbonImmutable;

/**
 * Liquidación de beneficios sociales al cese, con los parámetros del régimen de la persona
 * (LaborRegime). Montos brutos: no descuenta pensión ni renta. Un parámetro vacío en el régimen
 * deja ese concepto "sin parámetro" en vez de inventarlo.
 *
 *  - Vacaciones pendientes: días ganados y no gozados × remuneración diaria (R/30).
 *  - Vacaciones truncas: R × (días de vacaciones/30) por meses y días desde el último aniversario.
 *  - Gratificación trunca: R × (gratificaciones al año/2) / 6 por mes calendario completo del
 *    semestre (ene-jun, jul-dic) + la bonificación extraordinaria (%).
 *  - CTS trunca: (R + 1/6 de la gratificación) × CTS al año por meses y días desde el último
 *    periodo (1 de mayo, 1 de noviembre).
 *  - Indemnización (solo si se marca): días por año de servicio × R/30, con tope.
 */
class SettlementCalculator
{
    /**
     * @return array{lines: list<array{key: string, label: string, basis: string, amount: ?float}>, total: float, service: string, warnings: list<string>}
     */
    public function calculate(User $user, ?LaborRegime $regime, CarbonImmutable $end, float $salary, int $pendingVacationDays, bool $indemnity): array
    {
        $hire = CarbonImmutable::parse($user->hire_date->toDateString());
        $daily = $salary / 30;
        $money = fn (float $v) => round($v, 2);
        $param = fn (string $p) => $regime?->{$p};
        $lines = [];
        $warnings = [];
        if (! $regime) {
            $warnings[] = 'La persona no tiene régimen laboral en su ficha: solo se calculan las vacaciones pendientes.';
        }

        // Vacaciones: los días del régimen; en el general sin días propios, los de la empresa (como el saldo)
        $vacationDays = $param('vacation_days')
            ?? (in_array($regime?->code, ['general'], true) ? (int) TenantSetting::forTenant($user->tenant)->vacation_days_per_year : null);

        $lines[] = ['key' => 'vacation_pending', 'label' => 'Vacaciones pendientes (no gozadas)',
            'basis' => "{$pendingVacationDays} día(s) × " . $this->fmt($daily), 'amount' => $money($pendingVacationDays * $daily)];

        $years = (int) $hire->diffInYears($end->addDay());
        [$m, $d] = $this->span($hire->addYears($years), $end);
        $lines[] = $vacationDays === null
            ? $this->missing('vacation_truncated', 'Vacaciones truncas', 'días de vacaciones')
            : ['key' => 'vacation_truncated', 'label' => 'Vacaciones truncas', 'basis' => "{$m} mes(es) y {$d} día(s) · {$vacationDays} días por año",
                'amount' => $money($salary * $vacationDays / 30 * ($m / 12 + $d / 360))];

        $gratifications = $param('gratifications_per_year');
        $gratification = $gratifications === null ? null : $salary * $gratifications / 2;
        if ($gratification === null) {
            $lines[] = $this->missing('gratification', 'Gratificación trunca', 'gratificaciones al año');
        } else {
            $semester = $end->month <= 6 ? $end->startOfYear() : $end->setDate($end->year, 7, 1);
            $months = $this->fullMonths($hire->max($semester), $end);
            $amount = $gratification / 6 * $months;
            $pct = (float) ($param('gratification_bonus_pct') ?? 0);
            $lines[] = ['key' => 'gratification', 'label' => 'Gratificación trunca', 'basis' => "{$months} mes(es) completo(s) del semestre", 'amount' => $money($amount)];
            $lines[] = ['key' => 'gratification_bonus', 'label' => 'Bonificación extraordinaria', 'basis' => "{$pct} % de la gratificación", 'amount' => $money($amount * $pct / 100)];
        }

        $cts = $param('cts_per_year');
        if ($cts === null) {
            $lines[] = $this->missing('cts', 'CTS trunca', 'CTS al año');
        } else {
            $period = match (true) {
                $end->month >= 11 => $end->setDate($end->year, 11, 1),
                $end->month >= 5  => $end->setDate($end->year, 5, 1),
                default           => $end->setDate($end->year - 1, 11, 1),
            };
            [$m, $d] = $this->span($hire->max($period), $end);
            $computable = $salary + ($gratification ?? 0) / 6;
            $lines[] = ['key' => 'cts', 'label' => 'CTS trunca', 'basis' => "{$m} mes(es) y {$d} día(s) desde el " . $hire->max($period)->format('d/m/Y') . ' · base ' . $this->fmt($computable),
                'amount' => $money($computable * $cts * ($m / 12 + $d / 360))];
        }

        [$y, $m, $d] = $this->service($hire, $end);
        if ($indemnity) {
            $perYear = $param('indemnity_days_per_year');
            if ($perYear === null) {
                $lines[] = $this->missing('indemnity', 'Indemnización por despido', 'días de indemnización por año');
            } else {
                $days = $perYear * ($y + $m / 12 + $d / 360);
                $cap = $param('indemnity_cap_days');
                $capped = $cap !== null && $days > $cap;
                $lines[] = ['key' => 'indemnity', 'label' => 'Indemnización por despido',
                    'basis' => round(min($days, $cap ?? $days), 2) . ' día(s) de remuneración' . ($capped ? " (tope {$cap})" : ''),
                    'amount' => $money(min($days, $cap ?? $days) * $daily)];
            }
        }

        return [
            'lines'    => $lines,
            'total'    => $money(array_sum(array_map(fn ($l) => $l['amount'] ?? 0, $lines))),
            'service'  => "{$y} año(s), {$m} mes(es) y {$d} día(s)",
            'warnings' => $warnings,
        ];
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
        return ['key' => $key, 'label' => $label, 'basis' => "Sin parámetro: define «{$param}» en el régimen", 'amount' => null];
    }

    private function fmt(float $v): string
    {
        return number_format($v, 2, '.', ',');
    }
}
