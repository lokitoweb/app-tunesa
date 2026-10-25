<?php

namespace Tests\Unit;

use App\Modules\Payroll\Models\Payslip;
use PHPUnit\Framework\TestCase;

/**
 * Con MySQL sin tipos nativos (PDO emulado) las columnas llegan como texto;
 * la app espera números en el JSON de boletas.
 */
class PayslipCastsTest extends TestCase
{
    public function test_period_and_size_are_integers_even_when_the_driver_returns_strings(): void
    {
        $p = (new Payslip())->setRawAttributes(['period_month' => '10', 'period_year' => '2026', 'file_size' => '2048']);

        $this->assertSame(10, $p->period_month);
        $this->assertSame(2026, $p->period_year);
        $this->assertSame(2048, $p->file_size);
    }
}
