<?php

namespace App\Modules\Finance\Notifications;

use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Finance\Models\PaymentInstallment;
use App\Modules\Finance\Services\FinanceDocumentPdf;

/** A quien solicitó: Tesorería registró un pago de su requerimiento (sisgere FrmNOTIFICADOR: "Se registró un pago") */
class RequisitionPaid extends AppNotification
{
    public function __construct(public readonly PaymentInstallment $installment, public readonly float $amount) {}

    public function type(): string
    {
        return 'requisition.paid';
    }

    public function title(): string
    {
        return 'Se registró un pago';
    }

    public function body(): string
    {
        $r = $this->installment->requisition;

        return "{$r->orderNumber()} · cuota {$this->installment->installment}: " . FinanceDocumentPdf::money($r->currency, $this->amount) . " · {$r->detail}";
    }

    public function payload(): array
    {
        return ['requisition_id' => $this->installment->requisition_id, 'screen' => 'requisitions'];
    }
}
