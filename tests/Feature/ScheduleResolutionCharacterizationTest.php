<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Models\OffShiftAuthorization;
use App\Modules\Attendance\Notifications\EntryReminder;
use App\Modules\Attendance\Services\AttendanceCalendarService;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Services\CalendarFilters;
use App\Modules\Attendance\Services\LiveBoardService;
use App\Modules\Attendance\Services\ManualAttendanceEdit;
use App\Modules\Attendance\Services\Reports\AttendanceReportService;
use App\Modules\Attendance\Services\Reports\ReportFilters;
use App\Modules\Attendance\Services\ScheduleGate;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Shift;
use App\Modules\Integrations\Services\Push\FcmSender;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * QA Ronda 2 · caracterización (docs/qa/MAPA_FASE0.md §2 y §4).
 *
 * Fija el comportamiento ACTUAL de las 7 copias de "turno del día", del respaldo de
 * getCurrentShift y de las dos fórmulas de horas trabajadas, ANTES de unificarlas.
 * Los métodos test_actual_* documentan lo que hace hoy el sistema, no lo correcto:
 * cuando la unificación cambie un resultado, la prueba se actualiza en esa fase y el
 * cambio queda a la vista en el diff.
 *
 * Escenario: dos asignaciones vigentes a la vez (posible con replace_current=false):
 *   A: lun-vie 08:00-15:00, vigente desde 2026-01-01 (la más antigua)
 *   B: sábado 14:00-21:00, vigente desde 2026-06-01 (la más reciente)
 * Lunes 05/10/2026 le toca A, sábado 10/10 le toca B y domingo 11/10 ninguna.
 */
class ScheduleResolutionCharacterizationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected const MONDAY = '2026-10-05';
    protected const SATURDAY = '2026-10-10';
    protected const SUNDAY = '2026-10-11';

    protected Tenant $acme;
    protected Branch $branch;
    protected User $hr;
    protected User $emp;
    protected Shift $a;
    protected Shift $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('public');
        Storage::fake('private');

        $this->acme = $this->makeTenant('acme');
        $this->hr = $this->makeUser($this->acme, 'hr_manager');
        $this->branch = $this->makeBranch($this->acme);
        $this->emp = $this->makeUser($this->acme, 'employee', ['first_name' => 'Juan', 'last_name' => 'Perez', 'fcm_token' => 'device-juan']);
        $this->emp->branches()->attach($this->branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);

        $this->a = $this->shift('A', '08:00', '15:00', [1, 2, 3, 4, 5]);
        $this->b = $this->shift('B', '14:00', '21:00', [6]);
        $this->assign($this->emp, $this->a, '2026-01-01');
        $this->assign($this->emp, $this->b, '2026-06-01');
    }

    // =========================================================================
    // Copias 1 a 4: eligen la asignación que trabaja ese día
    // =========================================================================

    public function test_actual_get_current_shift_picks_the_working_one_and_falls_back_to_the_first(): void
    {
        $service = app(AttendanceService::class);

        $this->assertSame('A', $service->getCurrentShift($this->emp, self::MONDAY)[0]->shift->name);
        $this->assertSame('B', $service->getCurrentShift($this->emp, self::SATURDAY)[0]->shift->name);

        // Respaldo: el domingo no trabaja ninguna y aun así devuelve la primera vigente
        [$sunday, $hasAssignments] = $service->getCurrentShift($this->emp, self::SUNDAY);
        $this->assertSame('A', $sunday->shift->name);
        $this->assertTrue($hasAssignments);
    }

    public function test_actual_schedule_gate_has_no_fallback(): void
    {
        $gate = app(ScheduleGate::class);

        $monday = $gate->statusFor($this->emp, self::MONDAY);
        $this->assertTrue($monday['scheduled']);
        $this->assertSame(['A', '08:00', '15:00'], [$monday['shift']['name'], $monday['shift']['start_time'], $monday['shift']['end_time']]);

        $this->assertSame('B', $gate->statusFor($this->emp, self::SATURDAY)['shift']['name']);

        $sunday = $gate->statusFor($this->emp, self::SUNDAY);
        $this->assertFalse($sunday['scheduled']);
        $this->assertSame('rest_day', $sunday['reason']);
        $this->assertNull($sunday['shift']);

        $nobody = $this->makeUser($this->acme, 'employee');
        $this->assertSame('unscheduled', $gate->statusFor($nobody, self::MONDAY)['reason']);
    }

    public function test_actual_mark_absences_uses_the_working_assignment(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 02:00', 'America/Lima'));

        foreach ([self::MONDAY, self::SATURDAY, self::SUNDAY] as $day) {
            $this->artisan('attendance:mark-absences', ['--date' => $day])->assertSuccessful();
        }

        $absences = Attendance::withoutGlobalScopes()->where('user_id', $this->emp->id)->orderBy('work_date')->get();
        $this->assertSame(
            [[self::MONDAY, 'absent', $this->a->id], [self::SATURDAY, 'absent', $this->b->id]],
            $absences->map(fn (Attendance $r) => [$r->work_date->toDateString(), $r->status, $r->shift_id])->all(),
        );
    }

    public function test_actual_entry_reminder_uses_the_working_assignment(): void
    {
        Notification::fake();
        // El recordatorio solo sale por push: simula un FCM configurado
        $this->mock(FcmSender::class)->shouldReceive('canSendTo')->andReturn(true);

        $this->travelTo(Carbon::parse(self::MONDAY . ' 07:56', 'America/Lima'));
        $this->artisan('attendance:remind-entry')->assertSuccessful();
        Notification::assertSentTo($this->emp, EntryReminder::class, fn (EntryReminder $n) => $n->shift->name === 'A');

        $this->travelTo(Carbon::parse(self::SATURDAY . ' 13:56', 'America/Lima'));
        $this->artisan('attendance:remind-entry')->assertSuccessful();
        Notification::assertSentTo($this->emp, EntryReminder::class, fn (EntryReminder $n) => $n->shift->name === 'B');

        Notification::assertSentToTimes($this->emp, EntryReminder::class, 2);
    }

    public function test_actual_app_schedule_uses_the_working_assignment(): void
    {
        // Octava copia: calendario "Mis turnos" de la app (GET /api/schedule)
        $days = collect($this->actingIn($this->emp, $this->acme)->getJson('/api/schedule?from=' . self::MONDAY . '&to=' . self::SUNDAY)
            ->assertOk()->json('data'))->keyBy('date');

        $this->assertSame(['A', '08:00'], [$days[self::MONDAY]['shift']['name'], $days[self::MONDAY]['shift']['start_time']]);
        $this->assertSame('B', $days[self::SATURDAY]['shift']['name']);
        $this->assertSame([false, null], [$days[self::SUNDAY]['is_workday'], $days[self::SUNDAY]['shift']]);
    }

    // =========================================================================
    // Copias 5 a 7: eligen la de vigencia más reciente (B) y luego preguntan si trabaja
    // =========================================================================

    public function test_actual_calendar_takes_the_latest_assignment_and_shows_monday_as_rest(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 12:00', 'America/Lima'));

        $row = collect(app(AttendanceCalendarService::class)->paginate(new CalendarFilters($this->acme->id, '2026-10', viewer: $this->hr))->items())
            ->firstWhere('user_id', $this->emp->id);

        // Le toca A el lunes, pero el calendario mira B (la más reciente) y lo pinta de descanso
        $this->assertSame('rest', $row['cells'][self::MONDAY]['state']);
        $this->assertSame('absent', $row['cells'][self::SATURDAY]['state']);
        $this->assertSame('rest', $row['cells'][self::SUNDAY]['state']);
    }

    public function test_actual_report_takes_the_latest_assignment_and_skips_monday(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 12:00', 'America/Lima'));

        $report = collect(iterator_to_array(app(AttendanceReportService::class)->each(new ReportFilters($this->acme->id, self::MONDAY, self::SUNDAY, viewer: $this->hr)), false))
            ->firstWhere('user_id', $this->emp->id);

        // De lunes a viernes le toca A (5 días) y el sábado B, pero el reporte solo ve el sábado
        $this->assertSame(1, $report['summary']['scheduled_days']);
        $this->assertSame(1, $report['summary']['absences_unjustified']);
        $this->assertSame([self::SATURDAY], array_column($report['days'], 'date'));
    }

    public function test_actual_live_board_takes_the_latest_assignment(): void
    {
        $board = app(LiveBoardService::class);

        // Lunes: le toca A, pero el tablero mira B y no lo lista ("descansa hoy")
        $this->travelTo(Carbon::parse(self::MONDAY . ' 07:50', 'America/Lima'));
        $this->assertNull(collect($board->snapshot($this->acme->id, $this->hr)['rows'])->firstWhere('user_id', $this->emp->id));

        $this->travelTo(Carbon::parse(self::SATURDAY . ' 13:50', 'America/Lima'));
        $row = collect($board->snapshot($this->acme->id, $this->hr)['rows'])->firstWhere('user_id', $this->emp->id);
        $this->assertSame(['B', '02:00 pm', 'upcoming'], [$row['shift'], $row['shift_start'], $row['status']]);
    }

    // =========================================================================
    // Flujos que dependen del respaldo de getCurrentShift (MAPA §2)
    // =========================================================================

    public function test_actual_clock_in_on_an_authorized_rest_day_measures_against_the_fallback_shift(): void
    {
        OffShiftAuthorization::create([
            'tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'work_date' => self::SUNDAY,
            'reason' => 'Inventario', 'authorized_by' => $this->hr->id,
        ]);

        $this->travelTo(Carbon::parse(self::SUNDAY . ' 09:00', 'America/Lima'));
        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->punch())->assertCreated();

        $day = Attendance::withoutGlobalScopes()->where('user_id', $this->emp->id)->first();
        // Sin turno ese domingo, la tardanza se mide contra A (08:00): 60 min
        $this->assertSame([$this->a->id, 60, 'late'], [$day->shift_id, (int) $day->late_minutes, $day->status]);
        $this->assertContains(AttendancePunch::REASON_OUTSIDE_SCHEDULE, $day->clock_in_meta['suspicion_reasons'] ?? []);
    }

    public function test_actual_manual_edit_on_a_rest_day_measures_against_the_fallback_shift(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 12:00', 'America/Lima'));
        $this->actingAs($this->hr);

        $day = app(ManualAttendanceEdit::class)->save($this->hr, $this->emp, self::SUNDAY, '09:00', '17:00', null, 'Inventario de domingo');

        // Tardanza contra A (08:00) y break fijo de A (60 min): 8 h marcadas → 7 h
        $this->assertSame([$this->a->id, 60, 7.0, 'late'], [$day->shift_id, (int) $day->late_minutes, (float) $day->worked_hours, $day->status]);
        $this->assertSame($this->branch->id, $day->branch_id);
    }

    // =========================================================================
    // Dos fórmulas de horas trabajadas (RP-01 · CP-01)
    // =========================================================================

    public function test_actual_worked_hours_differ_between_the_saved_day_and_the_report(): void
    {
        // Fixture del informe (CP-01): turno 08:00-15:00 con los valores por defecto de la migración
        $ana = $this->makeUser($this->acme, 'employee');
        $ana->branches()->attach($this->branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $this->assign($ana, $this->a, '2026-01-01');
        $this->a->refresh();
        $this->assertSame([10, true, 60, '8.00'], [(int) $this->a->grace_period_minutes, (bool) $this->a->break_allowed, (int) $this->a->break_duration_minutes, (string) $this->a->daily_hours]);

        $this->travelTo(Carbon::parse('2026-10-01 08:06', 'America/Lima'));
        $this->actingIn($ana, $this->acme)->post('/api/attendance/clock-in', $this->punch())->assertCreated();
        $this->travelTo(Carbon::parse('2026-10-01 15:03', 'America/Lima'));
        $this->actingIn($ana, $this->acme)->post('/api/attendance/clock-out', $this->punch())->assertOk();

        $day = Attendance::withoutGlobalScopes()->where('user_id', $ana->id)->first();

        // RP-02: dentro de la tolerancia (10 min) no hay tardanza y el estado es normal
        $this->assertSame([0, 'present'], [(int) $day->late_minutes, $day->status]);

        // Guardada: 6 h 57 min menos el break fijo del turno (60) = 5 h 57 min
        $this->assertSame(5.95, (float) $day->worked_hours);

        // Reporte: solo descuenta el break marcado (ninguno) = 6 h 57 min
        $calc = app(AttendanceReportService::class)->calculate($day, $this->a, false);
        $this->assertSame([417, 0], [$calc['worked_minutes'], $calc['break_minutes']]);
    }

    // =========================================================================

    protected function shift(string $name, string $start, string $end, array $days): Shift
    {
        return Shift::create([
            'tenant_id' => $this->acme->id, 'name' => $name, 'type' => 'fixed',
            'start_time' => $start, 'end_time' => $end, 'working_days' => $days,
        ]);
    }

    protected function assign(User $user, Shift $shift, string $from): EmployeeShift
    {
        return EmployeeShift::create([
            'tenant_id' => $this->acme->id, 'user_id' => $user->id, 'shift_id' => $shift->id,
            'branch_id' => $this->branch->id, 'effective_from' => $from, 'is_active' => true,
        ]);
    }

    protected function punch(): array
    {
        return [
            'latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12,
            'device_id' => 'test-device', 'device_os' => 'android', 'is_mocked' => 0,
            'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
        ];
    }
}
