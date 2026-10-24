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
            'title'     => $isPurchase ? 'ORDEN DE COMPRA' : 'ORDEN DE SERVICIO',
            'number'    => $r->orderNumber(),
            'date'      => $r->ordered_at?->timezone(tenant_timezone())->format('d/m/Y'),
            'tenant'    => $r->tenant,
            'supplier'  => $r->supplier,
            'rows'      => [
                ['Requerimiento', $r->code()], ['Detalle', $r->detail], ['Área', $r->area], ['Placa', $r->plate],
                ['Condición', trim("{$r->payment_terms} · {$r->payment_method}", ' ·')], ['Cuotas', $r->installments],
            ],
            'items'     => $r->items->map(fn ($i) => [$i->product?->name, $i->product?->unit, (float) $i->quantity])->all(),
            'amount'    => (float) $r->amount,
            'currency'  => $r->currency,
            'signature' => self::signature($r->reviewed_by_id, 'Aprobado por'),
        ]);
    }

    public static function paymentOrder(PaymentInstallment $p): string
    {
        $p->loadMissing(['requisition.supplier.bankAccounts', 'requisition.tenant']);
        $r = $p->requisition;

        return self::render([
            'title'     => 'ORDEN DE PAGO',
            'number'    => sprintf('OP-%s-%02d', $r->orderNumber(), $p->installment),
            'date'      => $p->scheduled_for?->format('d/m/Y'),
            'tenant'    => $r->tenant,
            'supplier'  => $r->supplier,
            'rows'      => [
                ['Orden', $r->orderNumber()], ['Requerimiento', $r->code()], ['Factura', trim("{$r->invoice_number} " . ($r->invoice_date?->format('d/m/Y') ?? ''))],
                ['Cuota', "{$p->installment} de {$r->installments}"], ['Vence', $p->due_date?->format('d/m/Y')],
                ['Cuentas del proveedor', $r->supplier?->bankAccounts->map(fn ($a) => "{$a->bank} {$a->account_number}")->implode(' · ')],
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
