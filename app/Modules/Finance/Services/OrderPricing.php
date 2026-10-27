<?php

namespace App\Modules\Finance\Services;

/**
 * Importes de una OC/OS a partir de sus ítems (sisgere FrmReq: tbldetalle y botones Igv / S/Igv).
 *
 *   subtotal de línea = cantidad × precio unitario × (1 − descuento %)
 *   Precios CON IGV:  total = Σ subtotales; valor venta = total ÷ (1 + tasa); IGV = total − valor venta
 *   Precios SIN IGV:  valor venta = Σ subtotales; IGV = valor venta × tasa; total = valor venta + IGV
 *
 * Una sola fuente para la pantalla, el guardado y el PDF: los tres dan el mismo número.
 */
final class OrderPricing
{
    /**
     * @param  list<array{quantity: float|int|string, unit_price: float|int|string, discount_percent?: float|int|string|null}>  $lines
     * @return array{lines: list<float>, taxable: float, igv: float, total: float, rate: float, includes_igv: bool}
     */
    public static function compute(array $lines, bool $pricesIncludeIgv, ?float $rate = null): array
    {
        $rate ??= (float) config('finance.igv_rate', 0.18);
        $subtotals = array_map(fn ($l) => self::lineSubtotal($l['quantity'], $l['unit_price'], $l['discount_percent'] ?? 0), $lines);
        $sum = round(array_sum($subtotals), 2);

        if ($pricesIncludeIgv) {
            $total = $sum;
            $taxable = round($total / (1 + $rate), 2);
            $igv = round($total - $taxable, 2);
        } else {
            $taxable = $sum;
            $igv = round($taxable * $rate, 2);
            $total = round($taxable + $igv, 2);
        }

        return ['lines' => $subtotals, 'taxable' => $taxable, 'igv' => $igv, 'total' => $total, 'rate' => $rate, 'includes_igv' => $pricesIncludeIgv];
    }

    /**
     * Detracción en soles enteros, como calcular_detrac de sisgere: se redondea a un decimal y
     * luego al entero (desde ,5 hacia arriba). SUNAT pide el depósito sin céntimos.
     */
    public static function detraction(float $total, int|float $percent): float
    {
        return (float) round(round($total * $percent / 100, 1));
    }

    /**
     * Cuotas: iguales (la última absorbe el redondeo) o las escritas a mano, que deben sumar el neto.
     *
     * @param  list<array{due_date: string, amount: float|string}>|null  $manual
     * @return list<array{due_date: string, amount: float}>|null  null si las cuotas manuales no suman el neto
     */
    public static function installments(float $payable, int $count, string $firstDue, int $intervalDays, ?array $manual = null): ?array
    {
        if ($manual) {
            $rows = array_map(fn ($m) => ['due_date' => $m['due_date'], 'amount' => round((float) $m['amount'], 2)], $manual);

            return abs(array_sum(array_column($rows, 'amount')) - $payable) < 0.005 ? $rows : null;
        }

        $base = floor(($payable / $count) * 100) / 100;
        $due = \Illuminate\Support\Carbon::parse($firstDue);
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['due_date' => $due->toDateString(), 'amount' => $i === $count ? round($payable - $base * ($count - 1), 2) : $base];
            $due->addDays($intervalDays);
        }

        return $rows;
    }

    public static function lineSubtotal(float|int|string $quantity, float|int|string $unitPrice, float|int|string|null $discountPercent = 0): float
    {
        $discount = min(100, max(0, (float) $discountPercent));

        return round((float) $quantity * (float) $unitPrice * (1 - $discount / 100), 2);
    }
}
