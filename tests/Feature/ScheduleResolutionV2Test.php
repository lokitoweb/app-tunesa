<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Models\OffShiftAuthorization;
use App\Modules\Attendance\Services\AttendanceCalendarService;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Services\CalendarFilters;
use App\Modules\Attendance\Services\LiveBoardService;
use App\Modules\Attendance\Services\ManualAttendanceEdit;
use App\Modules\Attendance\Services\Reports\AttendanceReportService;
use App\Modules\Attendance\Services\Reports\ReportFilters;
use Carbon\Carbon;

/**
 * El mismo escenario de ScheduleResolutionCharacterizationTest con schedule_v2 encendida.
 *
 * Las pruebas heredadas sin sobrescribir pasan igual en los dos modos (gate, faltas,
 * recordatorio y fórmulas de horas). Las sobrescritas muestran lo que cambia: todas las
 * copias eligen la asignación que trabaja ese día y un día sin turno ya no se mide contra
 * otro horario (decisión B).
 */
class ScheduleResolutionV2Test extends ScheduleResolutionCharacterizationTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('attendance:flag', ['tenant' => 'acme', 'flag' => 'schedule_v2', 'state' => 'on', '--reason' => 'Prueba en modo v2'])
            ->assertSuccessful();
    }

    public function test_actual_get_current_shift_picks_the_working_one_and_falls_back_to_the_first(): void
    {
        $service = app(AttendanceService::class);

        $this->assertSame('A', $service->getCurrentShift($this->emp, self::MONDAY)[0]->shift->name);
        $this->assertSame('B', $service->getCurrentShift($this->emp, self::SATURDAY)[0]->shift->name);

        // Sin respaldo: el domingo no hay turno para calcular; la sede sale de la vigente más reciente
        [$sunday, $hasAssignments, $reference] = $service->getCurrentShift($this->emp, self::SUNDAY);
        $this->assertNull($sunday);
        $this->assertTrue($hasAssignments);
        $this->assertSame('B', $reference->shift->name);
    }

    public function test_actual_calendar_takes_the_latest_assignment_and_shows_monday_as_rest(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 12:00', 'America/Lima'));

        $row = collect(app(AttendanceCalendarService::class)->paginate(new CalendarFilters($this->acme->id, '2026-10', viewer: $this->hr))->items())
            ->firstWhere('user_id', $this->emp->id);

        // El lunes le toca A: es falta, no descanso
        $this->assertSame(['absent', 'A'], [$row['cells'][self::MONDAY]['state'], $row['cells'][self::MONDAY]['shift']]);
        $this->assertSame(['absent', 'B'], [$row['cells'][self::SATURDAY]['state'], $row['cells'][self::SATURDAY]['shift']]);
        $this->assertSame('rest', $row['cells'][self::SUNDAY]['state']);
    }

    public function test_actual_report_takes_the_latest_assignment_and_skips_monday(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 12:00', 'America/Lima'));

        $report = collect(iterator_to_array(app(AttendanceReportService::class)->each(new ReportFilters($this->acme->id, self::MONDAY, self::SUNDAY, viewer: $this->hr)), false))
            ->firstWhere('user_id', $this->emp->id);

        // De lunes a viernes con A y el sábado con B: 6 días programados, 6 faltas
        $this->assertSame(6, $report['summary']['scheduled_days']);
        $this->assertSame(6, $report['summary']['absences_unjustified']);
        $absent = array_values(array_filter($report['days'], fn ($d) => $d['status'] === 'absent'));
        $this->assertSame(['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', self::SATURDAY], array_column($absent, 'date'));
        // Corregido en RP-03/RP-04 (antes salía null): la fila de falta trae su turno y su horario programado
        $this->assertSame(['A', '08:00', '15:00'], [$absent[0]['shift'], $absent[0]['scheduled_in'], $absent[0]['scheduled_out']]);
        $this->assertSame(['B', '14:00'], [$absent[5]['shift'], $absent[5]['scheduled_in']]);
        $this->assertSame(['rest', 'Descanso'], [collect($report['days'])->firstWhere('date', self::SUNDAY)['day_status'], collect($report['days'])->firstWhere('date', self::SUNDAY)['day_status_label']]);
    }

    public function test_actual_live_board_takes_the_latest_assignment(): void
    {
        $board = app(LiveBoardService::class);

        $this->travelTo(Carbon::parse(self::MONDAY . ' 07:50', 'America/Lima'));
        $row = collect($board->snapshot($this->acme->id, $this->hr)['rows'])->firstWhere('user_id', $this->emp->id);
        $this->assertSame(['A', '08:00 am', 'upcoming'], [$row['shift'], $row['shift_start'], $row['status']]);

        $this->travelTo(Carbon::parse(self::SATURDAY . ' 13:50', 'America/Lima'));
        $row = collect($board->snapshot($this->acme->id, $this->hr)['rows'])->firstWhere('user_id', $this->emp->id);
        $this->assertSame(['B', '02:00 pm', 'upcoming'], [$row['shift'], $row['shift_start'], $row['status']]);
    }

    public function test_actual_clock_in_on_an_authorized_rest_day_measures_against_the_fallback_shift(): void
    {
        OffShiftAuthorization::create([
            'tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'work_date' => self::SUNDAY,
            'reason' => 'Inventario', 'authorized_by' => $this->hr->id,
        ]);

        $this->travelTo(Carbon::parse(self::SUNDAY . ' 09:00', 'America/Lima'));
        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->punch())->assertCreated();

        $day = Attendance::withoutGlobalScopes()->where('user_id', $this->emp->id)->first();
        // Sin turno ese domingo: sin tardanza contra otro horario, y queda marcada fuera de programación
        $this->assertSame([null, 0, 'present'], [$day->shift_id, (int) $day->late_minutes, $day->status]);
        $this->assertContains(AttendancePunch::REASON_OUTSIDE_SCHEDULE, $day->clock_in_meta['suspicion_reasons'] ?? []);
    }

    public function test_actual_manual_edit_on_a_rest_day_measures_against_the_fallback_shift(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 12:00', 'America/Lima'));
        $this->actingAs($this->hr);

        $day = app(ManualAttendanceEdit::class)->save($this->hr, $this->emp, self::SUNDAY, '09:00', '17:00', null, 'Inventario de domingo');

        // Sin turno: sin tardanza; descanso de la empresa (lunch_minutes 45): 8 h → 7.25 h; la sede sale de la vigente
        $this->assertSame([null, 0, 7.25, 'present'], [$day->shift_id, (int) $day->late_minutes, (float) $day->worked_hours, $day->status]);
        $this->assertSame($this->branch->id, $day->branch_id);
    }

    public function test_closed_month_in_cache_follows_the_flag_at_once(): void
    {
        $this->travelTo(Carbon::parse('2026-11-05 12:00', 'America/Lima'));
        $monday = fn () => collect(app(AttendanceCalendarService::class)->paginate(new CalendarFilters($this->acme->id, '2026-10', viewer: $this->hr))->items())
            ->firstWhere('user_id', $this->emp->id)['cells'][self::MONDAY]['state'];

        $this->assertSame('absent', $monday());
        $this->artisan('attendance:flag', ['tenant' => 'acme', 'flag' => 'schedule_v2', 'state' => 'off', '--reason' => 'Vuelta atrás'])->assertSuccessful();
        $this->assertSame('rest', $monday());
    }

    public function test_flag_off_by_default_and_command_is_audited(): void
    {
        $this->artisan('attendance:flag', ['tenant' => 'acme', 'flag' => 'schedule_v2'])->expectsOutput('acme · schedule_v2: on')->assertSuccessful();

        $log = \App\Modules\Core\Models\AuditLog::where('action', 'tenant.flag_changed')->sole();
        $this->assertSame($this->acme->id, $log->tenant_id);
        $this->assertSame('Prueba en modo v2', $log->meta['context']['reason']);
        // assertEquals: MySQL reordena las claves del JSON de la bitácora
        $this->assertEquals(['field' => 'flags.schedule_v2', 'old' => false, 'new' => true], collect($log->meta['changes'])->first());

        // Motivo obligatorio; una bandera desconocida se rechaza; otra empresa sigue apagada
        $this->artisan('attendance:flag', ['tenant' => 'acme', 'flag' => 'schedule_v2', 'state' => 'off'])->assertExitCode(2);
        $this->artisan('attendance:flag', ['tenant' => 'acme', 'flag' => 'otra_cosa', 'state' => 'on', '--reason' => 'Probar'])->assertExitCode(2);
        $this->makeTenant('globex');
        $this->artisan('attendance:flag', ['tenant' => 'globex', 'flag' => 'schedule_v2'])->expectsOutput('globex · schedule_v2: off');

        $this->artisan('attendance:flag', ['tenant' => 'acme', 'flag' => 'schedule_v2', 'state' => 'off', '--reason' => 'Vuelta atrás'])->assertSuccessful();
        $this->assertFalse((bool) $this->acme->fresh()->setting('flags.schedule_v2'));
        $this->assertSame(2, \App\Modules\Core\Models\AuditLog::where('action', 'tenant.flag_changed')->count());
    }
}
