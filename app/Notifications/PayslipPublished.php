<?php

namespace App\Notifications;

use App\Models\Payslip;

/** Al colaborador: tiene una boleta de pago nueva en la app */
class PayslipPublished extends AppNotification
{
    public function __construct(public readonly Payslip $payslip) {}

    public function type(): string
    {
        return 'payslip.published';
    }

    public function title(): string
    {
        return 'Nueva boleta de pago';
    }

    public function body(): string
    {
        return sprintf(
            'Ya puedes ver tu boleta de %02d/%d.',
            $this->payslip->period_month,
            $this->payslip->period_year
        );
    }

    public function payload(): array
    {
        return [
            'payslip_id'   => $this->payslip->id,
            'period_month' => (int) $this->payslip->period_month,
            'period_year'  => (int) $this->payslip->period_year,
            'screen'       => 'payslips',
        ];
    }
}
