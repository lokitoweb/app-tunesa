<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Duraciones en horas y minutos (panel), igual que Fmt.minutes en la app y Panel.minutes en el navegador */
class MinutesLabelTest extends TestCase
{
    public function test_minutes_are_shown_as_hours_and_minutes(): void
    {
        $this->assertSame('0 min', minutes_label(0));
        $this->assertSame('45 min', minutes_label(45));
        $this->assertSame('1 h', minutes_label(60));
        $this->assertSame('1 h 10 min', minutes_label(70));
        $this->assertSame('10 h 26 min', minutes_label(626));
        $this->assertSame('0 min', minutes_label(null));
        $this->assertSame('0 min', minutes_label(-3));
        $this->assertSame('2 h 5 min', minutes_label('125'));
    }
}
