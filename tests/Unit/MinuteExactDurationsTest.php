<?php

namespace Tests\Unit;

use App\Modules\Attendance\Models\Attendance;
use Tests\TestCase;

/**
 * Tardanza, horas y horas extra se calculan al minuto y se muestran en horas:minutos:segundos.
 * Las horas se guardan con 2 decimales: pasar por Attendance::minutes evita segundos falsos y que los
 * totales del mes se desvíen (20 jornadas de 7 h 32 min = 150:40:00, no 150:36:00).
 */
class MinuteExactDurationsTest extends TestCase
{
    public function test_durations_are_exact_to_the_minute_and_add_up(): void
    {
        $this->assertSame(452, Attendance::minutes(7.53));
        $this->assertSame('07:32:00', horas_hms(7.53));
        $this->assertSame('00:15:00', minutos_hms(15));
        $this->assertSame('00:00:00', horas_hms(null));

        // 9 h 32 min marcadas con 1 h 32 min de extra = 8 h de jornada exactas (no 7.999…)
        $day = new Attendance(['worked_hours' => 9.53, 'overtime_hours' => 1.53]);
        $this->assertSame(480, $day->regularMinutes());

        $month = collect(range(1, 20))->map(fn () => new Attendance(['worked_hours' => 7.53, 'overtime_hours' => 0]));
        $this->assertSame('150:40:00', minutos_hms($month->sum(fn (Attendance $a) => $a->regularMinutes())));
        $this->assertSame('150:40:00', horas_hms($month->sum(fn (Attendance $a) => $a->regularMinutes()) / 60));
    }
}
