<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Notifications\AttendanceIncompletePunches;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\HR\Controllers\Web\ShiftWebController;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * QA3-04 · 4 marcaciones: entrada · salida a refrigerio · retorno · salida.
 * Casos T1 a T6 del informe de QA n.º 3.
 */
class FourPunchShiftTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;
    private Branch $branch;
    private User $emp;
    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('public');
        Storage::fake('private');
        $this->travelTo(Carbon::parse('2026-11-02 07:55:00', 'America/Lima')); // lunes

        $this->acme   = $this->makeTenant('acme');
        $this->branch = $this->makeBranch($this->acme, ['name' => 'Ag. Trujillo']);
        $this->hr     = $this->makeUser($this->acme, 'hr_manager');
        $this->emp    = $this->makeUser($this->acme, 'employee', ['first_name' => 'Rosa', 'last_name' => 'Quispe']);
        $this->emp->branches()->attach($this->branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function selfie(): array
    {
        return ['latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12, 'device_id' => 'pixel', 'device_os' => 'android',
            'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640)];
    }

    private function gps(): array
    {
        return ['latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12];
    }

    private function assign(array $attrs): Shift
    {
        $shift = Shift::create(array_merge(['tenant_id' => $this->acme->id, 'branch_id' => $this->branch->id, 'name' => 'ADM-LV', 'type' => 'fixed',
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_period_minutes' => 10, 'break_allowed' => true, 'break_duration_minutes' => 60,
            'working_days' => [0, 1, 2, 3, 4, 5, 6], 'daily_hours' => 8, 'color' => '#3C729E'], $attrs));
        EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'shift_id' => $shift->id,
            'branch_id' => $this->branch->id, 'effective_from' => '2020-01-01', 'is_active' => true]);

        return $shift;
    }

    /** Marca a las horas dadas ("HH:MM" del día de prueba; null = no marca) */
    private function day(?string $in, ?string $breakStart, ?string $breakEnd, ?string $out): Attendance
    {
        $at = fn (string $hm) => $this->travelTo(Carbon::parse("2026-11-02 {$hm}:00", 'America/Lima'));
        if ($in) { $at($in); $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->selfie())->assertCreated(); }
        if ($breakStart) { $at($breakStart); $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/break-start', $this->gps())->assertOk(); }
        if ($breakEnd) { $at($breakEnd); $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/break-end', $this->gps())->assertOk(); }
        if ($out) { $at($out); $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-out', $this->selfie())->assertOk(); }

        return Attendance::where('user_id', $this->emp->id)->latest('id')->firstOrFail();
    }

    /** T1: el horario de 4 marcaciones se guarda desde el formulario y se ve completo */
    public function test_t1_shift_with_break_window_is_saved_and_listed_with_its_four_times(): void
    {
        $post = fn (array $extra) => $this->actingAs($this->hr)->post('/shifts', array_merge([
            'branch_id' => $this->branch->id, 'name' => 'ADM-LV', 'color' => '#3C729E', 'type' => 'fixed', 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_period_minutes' => 10, 'break_allowed' => true, 'break_duration_minutes' => 45, 'daily_hours' => 8, 'working_days' => [1, 2, 3, 4, 5], 'is_active' => true,
        ], $extra));

        $post(['break_start_time' => '13:00', 'break_end_time' => '14:00'])->assertSessionHasNoErrors();
        $shift = Shift::where('name', 'ADM-LV')->sole();
        $this->assertSame(['13:00', '14:00', 60], [substr($shift->break_start_time, 0, 5), substr($shift->break_end_time, 0, 5), (int) $shift->break_duration_minutes]);
        $this->assertTrue($shift->hasBreakWindow());
        $this->assertSame('08:00 · 13:00 · 14:00 · 17:00', $shift->timesLabel());

        $this->actingAs($this->hr)->getJson(route('shifts.schedule', ['branch_id' => $this->branch->id, 'month' => '2026-11']))->assertOk()
            ->assertJsonPath('shifts.0.times', '08:00 · 13:00 · 14:00 · 17:00');

        // Fuera de la jornada, al revés o solo una de las dos horas: no se guarda
        $post(['name' => 'Malo 1', 'color' => '#111111', 'break_start_time' => '14:00', 'break_end_time' => '13:00'])->assertSessionHasErrors('break_start_time');
        $post(['name' => 'Malo 2', 'color' => '#222222', 'break_start_time' => '07:00', 'break_end_time' => '08:30'])->assertSessionHasErrors('break_start_time');
        $post(['name' => 'Malo 3', 'color' => '#333333', 'break_start_time' => '13:00'])->assertSessionHasErrors('break_end_time');
        $this->assertSame(1, Shift::count());
    }

    /** Horario partido 08:00–12:00 y 18:00–22:00: se guarda desde el formulario, la app recibe los dos tramos y se pagan 8 h */
    public function test_split_shift_has_two_segments_in_the_app_and_eight_hours(): void
    {
        $this->actingAs($this->hr)->post('/shifts', [
            'branch_id' => $this->branch->id, 'name' => 'VTA-PARTIDO', 'color' => '#3C729E', 'type' => 'fixed', 'start_time' => '08:00', 'end_time' => '22:00',
            'grace_period_minutes' => 10, 'break_allowed' => true, 'break_duration_minutes' => 60, 'daily_hours' => 8, 'working_days' => [0, 1, 2, 3, 4, 5, 6],
            'is_active' => true, 'break_start_time' => '12:00', 'break_end_time' => '18:00', 'split_shift' => true,
        ])->assertSessionHasNoErrors();
        $shift = Shift::where('name', 'VTA-PARTIDO')->sole();
        $this->assertTrue($shift->isSplit());
        $this->assertSame(360, (int) $shift->break_duration_minutes);
        $this->assertSame('08:00–12:00 · 18:00–22:00', $shift->timesLabel());
        EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'shift_id' => $shift->id,
            'branch_id' => $this->branch->id, 'effective_from' => '2020-01-01', 'is_active' => true]);

        $this->actingIn($this->emp, $this->acme)->getJson('/api/schedule?from=2026-11-02&to=2026-11-02')->assertOk()
            ->assertJsonPath('data.0.shift.start_time', '08:00')->assertJsonPath('data.0.shift.break_start_time', '12:00')
            ->assertJsonPath('data.0.shift.break_end_time', '18:00')->assertJsonPath('data.0.shift.end_time', '22:00')
            ->assertJsonPath('data.0.shift.split', true);

        $this->travelTo(Carbon::parse('2026-11-02 08:00:00', 'America/Lima'));
        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->selfie())->assertCreated();
        $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'America/Lima'));
        $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/break-start', $this->gps())->assertOk()
            ->assertJsonPath('message', '✅ Fin del 1.er tramo registrado.');
        $att = $this->day(null, null, '18:00', '22:00');
        $this->assertEquals(8.0, (float) $att->worked_hours);
        $this->assertSame(0, $att->break_late_minutes);
        $this->assertFalse($att->punches_incomplete);
        $this->assertSame('present', $att->status);

        // Sin las horas de la pausa no puede ser partido
        $shift->update(['break_start_time' => null, 'break_end_time' => null]);
        $this->assertFalse($shift->fresh()->isSplit());
    }

    /** C7: sin las marcas del medio (o con refrigerio pagado) la pausa entre tramos no cuenta como trabajada */
    public function test_c7_split_shift_gap_is_never_worked_time(): void
    {
        // Guardado sin duración de pausa (API/importación): antes contaba 14 h - 0
        $this->assign(['start_time' => '08:00', 'end_time' => '22:00', 'break_start_time' => '12:00', 'break_end_time' => '18:00',
            'split_shift' => true, 'break_duration_minutes' => 0]);
        $this->assertEquals(8.0, (float) $this->day('08:00', null, null, '22:00')->worked_hours);

        // Con refrigerio pagado la pausa del partido igual se descuenta (no es refrigerio)
        \App\Modules\Core\Models\TenantSetting::editableRowFor($this->acme)->fill(['lunch_paid' => true])->save();
        Attendance::withoutGlobalScopes()->forceDelete();
        $this->assertEquals(8.0, (float) $this->day('08:00', null, null, '22:00')->worked_hours);

        // Solo el primer tramo: no hay pausa que descontar
        Attendance::withoutGlobalScopes()->forceDelete();
        $this->assertEquals(4.0, (float) $this->day('08:00', null, null, '12:00')->worked_hours);
    }

    /** Olvidó el retorno del refrigerio: RR. HH. lo corrige con las 4 horas y todo se recalcula como en la app */
    public function test_hr_corrects_a_forgotten_lunch_return_with_the_four_punches(): void
    {
        $this->assign(['break_start_time' => '13:00', 'break_end_time' => '14:00']);
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00', 'America/Lima'));
        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->selfie())->assertCreated();
        $this->travelTo(Carbon::parse('2026-11-02 13:00:00', 'America/Lima'));
        $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/break-start', $this->gps())->assertOk();
        $this->travelTo(Carbon::parse('2026-11-02 18:00:00', 'America/Lima'));
        $url = route('attendance.manual.update', ['id' => $this->emp->id, 'date' => '2026-11-02']);

        // La pantalla muestra lo que marcó (entrada y salida a refrigerio) y el horario de cada marcación
        $this->actingAs($this->hr)->get(route('attendance.manual.edit', ['id' => $this->emp->id, 'date' => '2026-11-02']))->assertOk()
            ->assertSee('Salida refrigerio')->assertSee('Retorno refrigerio')->assertSee('Marcó 13:00')->assertSee('horario 14:00')
            ->assertSee('value="08:00"', false)->assertSee('value="13:00"', false);

        // Solo una hora del refrigerio, o fuera de orden: no se guarda
        $this->actingAs($this->hr)->put($url, ['clock_in' => '08:00', 'break_start' => '13:00', 'clock_out' => '17:00', 'reason' => 'Olvidó marcar el retorno.'])
            ->assertInvalid('break_end');
        $this->actingAs($this->hr)->put($url, ['clock_in' => '08:00', 'break_start' => '13:00', 'break_end' => '14:20', 'clock_out' => '14:00', 'reason' => 'Olvidó marcar el retorno.'])
            ->assertInvalid('clock_out');

        $this->actingAs($this->hr)->put($url, [
            'clock_in' => '08:00', 'break_start' => '13:00', 'break_end' => '14:20', 'clock_out' => '17:20', 'reason' => 'Olvidó marcar el retorno; confirmado por el supervisor.',
        ])->assertValid();

        $att = Attendance::where('user_id', $this->emp->id)->sole();
        $this->assertSame(['13:00', '14:20', '17:20'], [$att->break_start_at->format('H:i'), $att->break_end_at->format('H:i'), $att->clock_out_at->format('H:i')]);
        $this->assertSame(80, (int) $att->break_minutes);
        $this->assertSame(20, (int) $att->break_late_minutes, 'retorno tardío con la tolerancia del turno');
        $this->assertFalse($att->punches_incomplete);
        $this->assertEquals(8.0, (float) $att->worked_hours, '9 h 20 min de jornada menos 80 min de refrigerio');
        $this->assertSame('present', $att->status);
        $this->assertNotNull($att->manual_edited_at);

        // Marcaciones y GPS muestra las 4 horas y que fue corregida; queda en la bitácora con el antes y después
        $this->actingAs($this->hr)->get('/attendance?start_date=2026-11-02&end_date=2026-11-02')->assertOk()->assertSee('Corregida');
        $this->assertTrue(\App\Modules\Core\Models\AuditLog::where('action', 'attendance.manual_edit')->exists());

        // Sin refrigerio en un horario de 4 marcaciones: queda como marcaciones incompletas (igual que en la app)
        $this->actingAs($this->hr)->put($url, ['clock_in' => '08:00', 'break_start' => '', 'break_end' => '', 'clock_out' => '17:00', 'reason' => 'Se quitó el refrigerio por error.'])->assertValid();
        $att->refresh();
        $this->assertNull($att->break_start_at);
        $this->assertTrue($att->punches_incomplete);
    }

    /** T2: las 4 marcaciones en horario → horas netas 8:00 y sin avisos */
    public function test_t2_four_punches_on_time_give_eight_net_hours(): void
    {
        $this->assign(['break_start_time' => '13:00', 'break_end_time' => '14:00']);
        $att = $this->day('08:00', '13:00', '14:00', '17:00');

        $this->assertEquals(8.0, (float) $att->worked_hours);
        $this->assertSame(0, $att->break_late_minutes);
        $this->assertFalse($att->punches_incomplete);
        $this->assertSame('present', $att->status);
    }

    /** T3: volver del refrigerio a las 14:20 → 20 min de retorno tardío; horas = (13−8) + (17−14:20) */
    public function test_t3_late_return_from_lunch_is_measured(): void
    {
        $this->assign(['break_start_time' => '13:00', 'break_end_time' => '14:00']);
        $att = $this->day('08:00', '13:00', '14:20', '17:00');

        $this->assertSame(20, $att->break_late_minutes);
        $this->assertEquals(7.67, (float) $att->worked_hours); // 5 h + 2 h 40 min

        // Dentro de la tolerancia (10 min) no cuenta
        Attendance::withoutGlobalScopes()->forceDelete();
        $this->travelTo(Carbon::parse('2026-11-03 07:55:00', 'America/Lima'));
        $this->assertSame(0, app(\App\Modules\Attendance\Services\AttendanceService::class)
            ->calculateBreakLateMinutes(Shift::sole(), '2026-11-03', 'America/Lima', Carbon::parse('2026-11-03 14:08:00', 'America/Lima')));
    }

    /** T4: horario de 2 marcaciones (sábado sin refrigerio) sigue funcionando sin pedir refrigerio */
    public function test_t4_two_punch_shift_does_not_ask_for_lunch(): void
    {
        Notification::fake();
        $this->assign(['name' => 'ADM-SB', 'end_time' => '13:00', 'break_allowed' => false, 'break_duration_minutes' => 0, 'daily_hours' => 5]);
        $att = $this->day('08:00', null, null, '13:00');

        $this->assertFalse($att->punches_incomplete);
        $this->assertEquals(5.0, (float) $att->worked_hours);
        Notification::assertNothingSentTo($this->hr);
    }

    /** T5: olvidar el retorno → jornada con marcaciones incompletas y aviso a RR. HH. */
    public function test_t5_forgotten_return_marks_incomplete_and_notifies_hr(): void
    {
        Notification::fake();
        $this->assign(['break_start_time' => '13:00', 'break_end_time' => '14:00']);
        $att = $this->day('08:00', '13:00', null, '17:00');

        $this->assertTrue($att->punches_incomplete);
        Notification::assertSentTo($this->hr, AttendanceIncompletePunches::class,
            fn ($n) => str_contains($n->body(), 'sin marcar el retorno del refrigerio'));
        Notification::assertNotSentTo($this->emp, AttendanceIncompletePunches::class);

        $this->actingAs($this->hr)->get(route('attendance.index', ['start_date' => '2026-11-02', 'end_date' => '2026-11-02']))->assertOk()
            ->assertSee('Marcaciones incompletas')->assertSee('Refrigerio');
    }

    /** T6: los horarios anteriores (sin horas de refrigerio) se ven y siguen como antes */
    public function test_t6_existing_shifts_keep_working_with_two_punches(): void
    {
        $old = $this->assign(['name' => 'CALL-M', 'start_time' => '07:00', 'end_time' => '15:00']);
        $this->assertFalse($old->hasBreakWindow());
        $this->assertSame('07:00–15:00', $old->timesLabel());

        // Sin marcar refrigerio: se descuenta el descanso fijo y no queda incompleta
        $att = $this->day('07:00', null, null, '15:00');
        $this->assertFalse($att->punches_incomplete);
        $this->assertEquals(7.0, (float) $att->worked_hours);
    }

    /** El orden del refrigerio también vale para turnos que cruzan la medianoche */
    public function test_break_window_rules_cover_night_shifts(): void
    {
        $this->assertSame(60, ShiftWebController::breakWindowMinutes('22:00', '06:00', '02:00', '03:00'));
        $this->assertSame(60, ShiftWebController::breakWindowMinutes('08:00', '17:00', '13:00', '14:00'));
        $this->assertNull(ShiftWebController::breakWindowMinutes('22:00', '06:00', '07:00', '08:00'));
        $this->assertNull(ShiftWebController::breakWindowMinutes('08:00', '17:00', '08:00', '09:00'));
    }
}
