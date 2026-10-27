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

    public static function lineSubtotal(float|int|string $quantity, float|int|string $unitPrice, float|int|string|null $discountPercent = 0): float
    {
        $discount = min(100, max(0, (float) $discountPercent));

        return round((float) $quantity * (float) $unitPrice * (1 - $discount / 100), 2);
    }
}
