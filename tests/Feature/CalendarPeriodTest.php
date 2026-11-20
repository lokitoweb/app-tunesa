<?php

namespace Tests\Feature;

use App\Modules\Attendance\Services\AttendanceCalendarService;
use App\Modules\Attendance\Services\CalendarFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Requerimiento Tunesa: el calendario (tareo) se puede ver y exportar por un periodo Desde/Hasta, no solo el mes */
class CalendarPeriodTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_calendar_shows_and_exports_a_chosen_period(): void
    {
        $this->seedRoles();
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00', 'America/Lima'));
        $acme = $this->makeTenant('acme');
        $hr   = $this->makeUser($acme, 'hr_manager');

        $week = new CalendarFilters($acme->id, '2026-11', from: '2026-11-09', to: '2026-11-15');
        $this->assertSame(['2026-11-09', '2026-11-15'], $week->range());
        $this->assertCount(7, app(AttendanceCalendarService::class)->days($week));
        $this->assertSame('09/11/2026 – 15/11/2026', $week->periodLabel());
        $this->assertSame(['from' => '2026-11-09', 'to' => '2026-11-15'], $week->toQuery());

        // Un periodo que cruza meses invalida su caché con los dos meses
        $this->assertSame(['2026-10', '2026-11'], (new CalendarFilters($acme->id, '2026-10', from: '2026-10-26', to: '2026-11-01'))->months());

        $this->actingAs($hr)->get(route('attendance.calendar', ['from' => '2026-11-09', 'to' => '2026-11-15']))->assertOk()
            ->assertSee('09/11/2026 – 15/11/2026')->assertSee('attCalCalendarDropdown', false)->assertSee('Últimos 7 días');

        $this->actingAs($hr)->get(route('attendance.calendar.excel', ['from' => '2026-11-09', 'to' => '2026-11-15']))->assertOk()
            ->assertDownload('calendario-asistencia-2026-11-09_2026-11-15.xlsx');

        // Hasta dos meses; Hasta no puede ser antes de Desde
        $this->actingAs($hr)->get(route('attendance.calendar', ['from' => '2026-01-01', 'to' => '2026-06-30']))->assertSessionHasErrors('to');
        $this->actingAs($hr)->get(route('attendance.calendar', ['from' => '2026-11-15', 'to' => '2026-11-09']))->assertSessionHasErrors('to');
    }
}
