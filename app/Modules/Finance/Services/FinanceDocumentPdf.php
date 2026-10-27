<?php

namespace App\Modules\Finance\Services;

use App\Modules\Core\Models\TenantSetting;
use App\Modules\Finance\Models\PaymentInstallment;
use App\Modules\Finance\Models\Requisition;
use App\Modules\Finance\Models\Signatory;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF oficiales de la traza: OC/OS (firma de quien aprobó el requerimiento) y OP (firma de
 * la persona designada que autorizó el pago). Una sola plantilla para todos.
 */
final class FinanceDocumentPdf
{
    public static function order(Requisition $r): string
    {
        $r->loadMissing(['items.product', 'supplier', 'tenant']);
        $isPurchase = (bool) $r->purchase_order_number;

        return self::render([
            'title'     => $isPurchase ? 'ORDEN DE COMPRA' : ($r->service_order_number ? 'ORDEN DE SERVICIO' : 'PAGO DIRECTO'),
            'number'    => $r->orderNumber(),
            'date'      => $r->ordered_at?->timezone(tenant_timezone())->format('d/m/Y'),
            'tenant'    => $r->tenant,
            'supplier'  => $r->supplier,
            'rows'      => [
                ['Requerimiento', $r->is_direct_payment ? null : $r->code()], ['Documento', $r->is_direct_payment ? $r->invoice_number : null], ['Detalle', $r->detail], ['Área', $r->area], ['Placa', $r->plate],
                ['Condición', trim("{$r->payment_terms} · {$r->payment_method}", ' ·')], ['Cuotas', $r->installments],
                ['Detracción', $r->detraction_reason ? "{$r->detraction_reason} - " . config("finance.detraction_reasons.{$r->detraction_reason}.0")
                    . " · {$r->detraction_percent} %: " . self::money($r->currency, $r->detraction_amount) : null],
                ['Neto a pagar', $r->detraction_reason ? self::money($r->currency, $r->amount_payable) : null],
            ],
            // [producto, unidad, cantidad, precio unitario, descuento %, importe]; sin precios, solo las 3 primeras
            'items'     => $r->items->map(fn ($i) => [$i->product?->name, $i->product?->unit, (float) $i->quantity,
                $i->unit_price !== null ? (float) $i->unit_price : null, (float) $i->discount_percent, $i->subtotal !== null ? (float) $i->subtotal : null])->all(),
            'amount'    => (float) $r->amount,
            'currency'  => $r->currency,
            // Valor venta, IGV y total (como VORDEN_COMPRA de sisgere); null si la orden no lleva precios por ítem
            'breakdown' => $r->igv_rate !== null ? [
                'taxable' => (float) $r->taxable_amount, 'igv' => (float) $r->igv_amount, 'rate' => (float) $r->igv_rate,
                'includes_igv' => (bool) $r->prices_include_igv,
            ] : null,
            'signature' => self::signature($r->reviewed_by_id, 'Aprobado por'),
        ]);
    }

    public static function paymentOrder(PaymentInstallment $p): string
    {
        $p->loadMissing(['requisition.supplier.bankAccounts', 'requisition.tenant', 'requisition.employee.profile']);
        $r = $p->requisition;
        // Pago de RRHH: el beneficiario es el colaborador y su cuenta del legajo (enmascarada en el PDF)
        $account = $r->isPayroll() ? $r->payrollAccount() : null;
        $payee = $r->isPayroll() ? (object) ['name' => $r->employee?->full_name, 'ruc' => 'DNI ' . $r->employee?->document_number] : $r->supplier;
        $accounts = $r->isPayroll()
            ? trim(($account['bank'] ?? '') . ($account['number'] ? ' ••••' . substr($account['number'], -4) : ''))
            : $r->supplier?->bankAccounts->map(fn ($a) => "{$a->bank} {$a->account_number}")->implode(' · ');

        return self::render([
            'title'     => 'ORDEN DE PAGO',
            'number'    => sprintf('OP-%s-%02d', $r->orderNumber(), $p->installment),
            'date'      => $p->scheduled_for?->format('d/m/Y'),
            'tenant'    => $r->tenant,
            'supplier'  => $payee,
            'rows'      => [
                ['Orden', $r->orderNumber()], ['Concepto', $r->isPayroll() ? $r->detail : null],
                ['Requerimiento', $r->isPayroll() ? null : $r->code()], ['Factura', trim("{$r->invoice_number} " . ($r->invoice_date?->format('d/m/Y') ?? ''))],
                ['Cuota', "{$p->installment} de {$r->installments}"], ['Vence', $p->due_date?->format('d/m/Y')],
                [$r->isPayroll() ? 'Cuenta del colaborador' : 'Cuentas del proveedor', $accounts],
            ],
            'items'     => [],
            'amount'    => round((float) $p->amount - (float) $p->paid_amount, 2),
            'currency'  => $r->currency,
            'signature' => self::signature($p->authorized_by_id, 'Autorizado por'),
        ]);
    }

    /** "S/ 1,200.00" — mismo formato en PDF y correos */
    public static function money(?string $currency, float|string|null $amount): string
    {
        return ($currency === 'USD' ? 'US$' : ($currency === 'EUR' ? '€' : 'S/')) . ' ' . number_format((float) $amount, 2, '.', ',');
    }

    /** Firma del apoderado (si el usuario lo es) o solo el nombre de quien aprobó */
    private static function signature(?int $userId, string $label): ?array
    {
        if (! $userId) {
            return null;
        }
        $signatory = Signatory::with('user')->where('user_id', $userId)->first();
        $user = $signatory?->user ?? \App\Modules\Core\Models\User::find($userId);

        return ['label' => $label, 'name' => $user?->full_name, 'position' => $signatory?->position, 'image' => $signatory?->signatureDataUri()];
    }

    private static function render(array $data): string
    {
        $settings = TenantSetting::forTenant($data['tenant']);
        $html = view('finance.document', $data + ['company' => $settings->tradeName(), 'color' => $settings->primaryColor()])->render();

        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }
}
