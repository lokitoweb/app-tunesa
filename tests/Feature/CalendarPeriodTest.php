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

    /** Pedido del ingeniero: una columna por semana (lunes a domingo) con las horas trabajadas; ningún día queda fuera */
    public function test_weeks_run_monday_to_sunday_and_cover_every_day(): void
    {
        $weeks = fn (string $month, ?string $from = null, ?string $to = null) => array_column((new CalendarFilters(1, $month, from: $from, to: $to))->weeks(), 'label');

        // El cuadro del ingeniero, más el 30/11 (lunes) y la semana del 28 al 31/12 que faltaban
        $this->assertSame(['Sem 1 · 01/11', 'Sem 2 · 02–08/11', 'Sem 3 · 09–15/11', 'Sem 4 · 16–22/11', 'Sem 5 · 23–29/11', 'Sem 6 · 30/11'], $weeks('2026-11'));
        $this->assertSame(['Sem 1 · 01–06/12', 'Sem 2 · 07–13/12', 'Sem 3 · 14–20/12', 'Sem 4 · 21–27/12', 'Sem 5 · 28–31/12'], $weeks('2026-12'));
        $this->assertSame(['Sem 1 · 01–03/01', 'Sem 2 · 04–10/01', 'Sem 3 · 11–17/01', 'Sem 4 · 18–24/01', 'Sem 5 · 25–31/01'], $weeks('2027-01'));
        $this->assertSame(['Sem 1 · 01–07/02', 'Sem 2 · 08–14/02', 'Sem 3 · 15–21/02', 'Sem 4 · 22–28/02'], $weeks('2027-02'));

        // Un periodo propio que cruza meses: la semana del 30/11 al 06/12 se parte en el cambio de mes
        $this->assertSame(['Sem 4 · 18–22/11', 'Sem 5 · 23–29/11', 'Sem 6 · 30/11', 'Sem 1 · 01–06/12', 'Sem 2 · 07–10/12'], $weeks('2026-11', '2026-11-18', '2026-12-10'));

        // Cada día del periodo está en una sola semana
        foreach (['2026-11', '2026-12', '2027-01', '2027-02'] as $month) {
            $f = new CalendarFilters(1, $month);
            $covered = collect($f->weeks())->sum(fn ($w) => Carbon::parse($w['from'])->diffInDays(Carbon::parse($w['to'])) + 1);
            $this->assertSame(count(app(AttendanceCalendarService::class)->days($f)), (int) $covered, $month);
        }
    }

    public function test_weekly_hours_add_up_to_the_total_on_screen_and_in_excel(): void
    {
        $this->seedRoles();
        $this->withoutPunchTimeLimits();
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00', 'America/Lima'));
        $acme = $this->makeTenant('acme');
        $hr = $this->makeUser($acme, 'hr_manager');
        $emp = $this->makeUser($acme, 'employee', ['first_name' => 'Ana', 'last_name' => 'Ruiz']);
        $branch = $this->makeBranch($acme);
        $emp->branches()->attach($branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        app(\App\Modules\Core\Services\TenantContext::class)->set($acme);
        // Domingo 01/11 (semana 1), lunes 02/11 y martes 03/11 (semana 2), lunes 16/11 (semana 4)
        foreach (['2026-11-01' => 6.5, '2026-11-02' => 8.0, '2026-11-03' => 7.75, '2026-11-16' => 8.0] as $date => $hours) {
            \App\Modules\Attendance\Models\Attendance::create(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'branch_id' => $branch->id,
                'work_date' => $date, 'clock_in_at' => "{$date} 08:00:00", 'clock_out_at' => "{$date} 17:00:00", 'status' => 'present', 'worked_hours' => $hours]);
        }

        $f = new CalendarFilters($acme->id, '2026-11', userId: $emp->id);
        $row = app(AttendanceCalendarService::class)->rows(collect([$emp->fresh()]), $f)[0];
        $this->assertSame([390, 945, 0, 480, 0, 0], $row['totals']['week_minutes']);
        $this->assertSame(array_sum($row['totals']['week_minutes']), \App\Modules\Attendance\Models\Attendance::minutes($row['totals']['worked_hours']));

        $this->actingAs($hr)->get(route('attendance.calendar', ['month' => '2026-11']))->assertOk()
            ->assertSee('02–08/11')->assertSee('06:30:00')->assertSee('15:45:00')->assertSee('30:15:00');

        $file = $this->actingAs($hr)->get(route('attendance.calendar.excel', ['month' => '2026-11']))->assertOk()->baseResponse->getFile()->getPathname();
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file)->getActiveSheet();
        $headers = $sheet->rangeToArray('A1:' . $sheet->getHighestColumn() . '1')[0];
        $first = array_search('Horas Sem 1 · 01/11', $headers, true);
        $this->assertNotFalse($first);
        $this->assertSame(['Horas Sem 6 · 30/11', 'Horas de jornada'], [$headers[$first + 5], $headers[$first + 6]]);
        // La fila de Ana (el orden es por apellido y el de RR. HH. es aleatorio)
        $line = collect(range(2, $sheet->getHighestRow()))->first(fn ($r) => $sheet->getCell([2, $r])->getValue() === 'Ruiz, Ana');
        $value = fn (int $index) => round((float) $sheet->getCell([$index + 1, $line])->getValue() * 1440);
        $this->assertSame([390.0, 945.0, 0.0, 480.0, 0.0, 0.0, 1815.0], array_map($value, range($first, $first + 6)));
    }
}
