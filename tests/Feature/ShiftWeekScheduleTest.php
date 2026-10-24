<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Services\Reports\AttendanceReportService;
use App\Modules\HR\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * DEF-01: turno con horario propio por día (sábado corto del personal
 * administrativo) y perfil. Tardanza, break y horas extra usan el horario
 * de ese día, no el general.
 */
class ShiftWeekScheduleTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const SATURDAY = '2026-10-10';
    private const MONDAY = '2026-10-12';

    private function adminShift(): Shift
    {
        return new Shift([
            'name' => 'Administrativo', 'type' => 'fixed', 'profile' => 'administrative',
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'crosses_midnight' => false,
            'grace_period_minutes' => 10, 'break_allowed' => true, 'break_duration_minutes' => 60, 'daily_hours' => 8,
            'working_days' => [1, 2, 3, 4, 5, 6],
            'day_schedules' => [6 => ['start_time' => '09:00', 'end_time' => '13:00', 'break_duration_minutes' => 0, 'daily_hours' => 4]],
        ]);
    }

    public function test_hr_saves_profile_and_only_the_days_that_differ(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $hr = $this->makeUser($acme, 'hr_manager');

        $this->actingAs($hr)->post('/shifts', [
            'name' => 'Oficina', 'type' => 'fixed', 'profile' => 'administrative',
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_period_minutes' => 10,
            'break_allowed' => true, 'break_duration_minutes' => 60, 'daily_hours' => 8,
            'working_days' => [1, 2, 3, 4, 5, 6], 'color' => '#0284C7', 'is_active' => true,
            'day_schedules' => [
                1 => ['start_time' => '', 'end_time' => '', 'break_duration_minutes' => '', 'daily_hours' => ''],
                6 => ['start_time' => '08:00', 'end_time' => '13:00', 'break_duration_minutes' => '0', 'daily_hours' => '5'],
                0 => ['start_time' => '09:00', 'end_time' => '12:00', 'break_duration_minutes' => '', 'daily_hours' => ''], // domingo no laborable
            ],
        ])->assertRedirect('/shifts')->assertSessionHasNoErrors();

        $shift = Shift::where('name', 'Oficina')->firstOrFail();
        $this->assertSame('administrative', $shift->profile);
        $this->assertSame(['6' => ['start_time' => '08:00', 'end_time' => '13:00', 'break_duration_minutes' => 0, 'daily_hours' => 5]], $shift->day_schedules);

        // Editar sin enviar el horario por día (otro cliente) lo conserva
        $this->actingAs($hr)->put("/shifts/{$shift->id}", [
            'name' => 'Oficina central', 'type' => 'fixed', 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_period_minutes' => 10, 'break_duration_minutes' => 60, 'daily_hours' => 8,
            'working_days' => [1, 2, 3, 4, 5, 6], 'color' => '#0284C7',
        ])->assertSessionHasNoErrors();
        $this->assertNotNull($shift->fresh()->day_schedules);

        $this->actingAs($hr)->post('/shifts', ['name' => 'X', 'type' => 'fixed', 'profile' => 'gerencia', 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_period_minutes' => 10, 'break_duration_minutes' => 60, 'daily_hours' => 8, 'working_days' => [1], 'color' => '#0284C7'])
            ->assertSessionHasErrors('profile');
    }

    public function test_for_date_returns_the_schedule_of_that_weekday(): void
    {
        $shift = $this->adminShift();

        $this->assertSame($shift, $shift->forDate(self::MONDAY), 'sin horario propio es el mismo turno');
        $saturday = $shift->forDate(self::SATURDAY);
        $this->assertSame('09:00', $saturday->start_time);
        $this->assertSame('13:00', $saturday->end_time);
        $this->assertSame(4, (int) $saturday->daily_hours);
        $this->assertSame('08:00:00', $shift->start_time, 'el turno original no cambia');
        $this->assertSame('09:00', $saturday->forDate(self::SATURDAY)->start_time, 'aplicarlo dos veces da lo mismo');
    }

    public function test_late_minutes_follow_the_day_schedule(): void
    {
        $service = app(AttendanceService::class);
        $shift = $this->adminShift();

        // 08:30 el lunes es tardanza; el sábado (entra 09:00) llega temprano
        $this->assertSame(30, $service->calculateLateMinutes($shift, self::MONDAY, 'America/Lima', \Carbon\Carbon::parse(self::MONDAY . ' 08:30', 'America/Lima')));
        $this->assertSame(0, $service->calculateLateMinutes($shift, self::SATURDAY, 'America/Lima', \Carbon\Carbon::parse(self::SATURDAY . ' 08:30', 'America/Lima')));
        $this->assertSame(20, $service->calculateLateMinutes($shift, self::SATURDAY, 'America/Lima', \Carbon\Carbon::parse(self::SATURDAY . ' 09:20', 'America/Lima')));
    }

    public function test_break_and_overtime_follow_the_day_schedule(): void
    {
        $report = new AttendanceReportService();
        $shift = $this->adminShift();

        // Sábado 09:00–14:00 sin refrigerio: 5 h trabajadas, jornada de 4 h → 1 h extra
        $saturday = $report->calculate(new Attendance([
            'work_date' => self::SATURDAY, 'clock_in_at' => self::SATURDAY . ' 09:00:00', 'clock_out_at' => self::SATURDAY . ' 14:00:00',
        ]), $shift, false);
        $this->assertSame(300, $saturday['worked_minutes']);
        $this->assertSame(60, $saturday['overtime_day_minutes']);

        // Sábado saliendo 12:00 (antes de las 13:00) es salida anticipada
        $early = $report->calculate(new Attendance([
            'work_date' => self::SATURDAY, 'clock_in_at' => self::SATURDAY . ' 09:00:00', 'clock_out_at' => self::SATURDAY . ' 12:00:00',
        ]), $shift, false);
        $this->assertSame(60, $early['early_minutes']);

        // El refrigerio fijo del sábado es 0; entre semana se descuentan 60 min
        $service = app(AttendanceService::class);
        $settings = new \App\Modules\Core\Models\TenantSetting(['lunch_minutes' => 60]);
        $this->assertSame(0, $service->breakToDeduct(300, 0, $shift->forDate(self::SATURDAY), $settings));
        $this->assertSame(60, $service->breakToDeduct(540, 0, $shift->forDate(self::MONDAY), $settings));
        $this->assertSame(4.0, AttendanceService::baseDailyHours($shift->forDate(self::SATURDAY), new \App\Modules\Core\Models\User()));
    }
}
