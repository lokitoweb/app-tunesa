<?php

namespace Tests\Feature;

use App\Modules\Attendance\Services\AttendanceFlags;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TenantContext;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Shift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * C9: schedule_v2 (horario por fecha) es la regla de todas las empresas.
 *  A. Con horario: dentro de la ventana, sin bandera; turno nocturno incluido.
 *  B. Sin horario asignado: marca igual, "Sin horario configurado", a revisión y un solo aviso al supervisor.
 *  C. Con horario y fuera de programación: se registra, "Fuera de programación", avisos agrupados por día.
 *  Vuelta atrás por empresa: attendance:flag <empresa> schedule_v2 off.
 */
class ScheduleV2DefaultTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;

    private Branch $branch;

    private User $emp;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('private');
        $this->seedRoles();
        $this->acme       = $this->makeTenant('acme');
        $this->supervisor = $this->makeUser($this->acme, 'supervisor');
        $this->branch     = $this->makeBranch($this->acme, ['allowed_radius_meters' => 100, 'supervisor_id' => $this->supervisor->id]);
        $this->emp        = $this->makeUser($this->acme, 'employee');
        $this->emp->branches()->attach($this->branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        TenantSetting::editableRowFor($this->acme)->fill(['punch_requires_schedule' => true])->save();
        app(TenantContext::class)->clear();
        $this->travelTo(Carbon::parse('2026-10-09 08:05', 'America/Lima')); // jueves
    }

    private function shift(string $start, string $end): void
    {
        $shift = Shift::create(['tenant_id' => $this->acme->id, 'name' => 'Turno', 'start_time' => $start, 'end_time' => $end,
            'grace_period_minutes' => 10, 'working_days' => [0, 1, 2, 3, 4, 5, 6]]);
        EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'shift_id' => $shift->id,
            'branch_id' => $this->branch->id, 'effective_from' => '2020-01-01', 'is_active' => true]);
        $this->emp->notifications()->delete();
    }

    private function punch(string $type, string $at, float $lat = -12.09418)
    {
        $this->travelTo(Carbon::parse($at, 'America/Lima'));

        return $this->actingIn($this->emp, $this->acme)->post("/api/attendance/{$type}", [
            'latitude' => $lat, 'longitude' => -77.03560, 'accuracy_meters' => 12,
            'device_id' => 'pixel-7', 'device_os' => 'android',
            'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
        ]);
    }

    public function test_schedule_v2_is_on_by_default_and_can_be_turned_off_per_company(): void
    {
        $this->assertTrue(AttendanceFlags::enabled($this->acme, 'schedule_v2'));

        $this->artisan('attendance:flag', ['tenant' => 'acme', 'flag' => 'schedule_v2', 'state' => 'off', '--reason' => 'prueba'])->assertSuccessful();
        $this->assertFalse(AttendanceFlags::enabled($this->acme->fresh(), 'schedule_v2'));
        app(TenantContext::class)->clear();

        // Apagada, quien no tiene horario vuelve a quedar bloqueado como antes
        $this->punch('clock-in', '2026-10-09 08:05')->assertStatus(422)->assertJsonPath('code', 'NOT_SCHEDULED');
    }

    public function test_a_scheduled_worker_inside_the_window_is_clean_including_night_shifts(): void
    {
        $this->shift('08:00', '17:00');
        $this->punch('clock-in', '2026-10-09 08:05')->assertCreated()->assertJsonPath('punch.suspicious', false);
        $this->punch('clock-out', '2026-10-09 17:05')->assertOk()->assertJsonPath('punch.suspicious', false);
        $this->assertSame(0, $this->supervisor->notifications()->count());
    }

    public function test_a_night_shift_crossing_midnight_is_judged_against_its_own_date(): void
    {
        $this->shift('22:00', '06:00');
        $this->punch('clock-in', '2026-10-09 21:55')->assertCreated()->assertJsonPath('punch.suspicious', false);
        $this->punch('clock-out', '2026-10-10 06:05', -12.094183)->assertOk()->assertJsonPath('punch.suspicion_reasons', []);
    }

    public function test_b_without_a_schedule_the_punch_is_kept_for_review_and_the_supervisor_is_told_once(): void
    {
        $this->actingIn($this->emp, $this->acme)->getJson('/api/attendance/today')->assertOk()
            ->assertJsonPath('schedule.can_clock_in', true)
            ->assertJsonPath('schedule.reason', 'unscheduled')
            ->assertJsonPath('schedule.message', \App\Modules\Attendance\Services\ScheduleGate::MESSAGE_UNCONFIGURED)
            ->assertJsonPath('next_action.action', 'clock_in');

        $this->punch('clock-in', '2026-10-09 08:05')->assertCreated()
            ->assertJsonPath('punch.suspicion_reasons', ['NO_SCHEDULE'])->assertJsonPath('punch.suspicious', true);
        $this->punch('clock-out', '2026-10-09 17:00', -12.094183)->assertOk()
            ->assertJsonPath('punch.suspicion_reasons', ['NO_SCHEDULE']);

        // Un solo aviso al supervisor por día; el colaborador ya lo ve en la app (no se le repite)
        $notes = $this->supervisor->notifications()->get();
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('sin horario configurado', $notes[0]->data['body']);
        $this->assertSame(0, $this->emp->notifications()->count());

        // Panel y app muestran el mismo estado
        $this->actingIn($this->emp, $this->acme)->getJson('/api/attendance/today')->assertJsonPath('data.status_label', 'Sin horario configurado');
        $hr = $this->makeUser($this->acme, 'hr_manager');
        $this->actingAs($hr)->get('/attendance?date_from=2026-10-09&date_to=2026-10-09')->assertOk()
            ->assertSee('Sin horario configurado')->assertDontSee('Fuera de programación');

        // No es falta: el proceso de faltas solo mira a quien tenía turno
        $this->travelTo(Carbon::parse('2026-10-10 23:30', 'America/Lima'));
        $this->artisan('attendance:mark-absences', ['--date' => '2026-10-10'])->assertSuccessful();
        $this->assertSame(0, \App\Modules\Attendance\Models\Attendance::withoutGlobalScopes()->where('work_date', '2026-10-10')->count());
    }

    public function test_c_outside_the_window_is_kept_flagged_and_notifications_are_grouped_per_day(): void
    {
        $this->shift('08:00', '17:00');
        $this->punch('clock-in', '2026-10-09 07:30')->assertCreated()->assertJsonPath('punch.suspicion_reasons', ['OUTSIDE_SCHEDULE']);
        $this->punch('clock-out', '2026-10-09 17:40', -12.094183)->assertOk()->assertJsonPath('punch.suspicion_reasons', ['OUTSIDE_SCHEDULE']);

        $this->assertSame(['attendance.outside_schedule'], $this->supervisor->notifications()->pluck('type')->all());
        $this->assertSame(['attendance.outside_schedule'], $this->emp->notifications()->pluck('type')->all());
        $hr = $this->makeUser($this->acme, 'hr_manager');
        $this->actingAs($hr)->get('/attendance?date_from=2026-10-09&date_to=2026-10-09')->assertOk()->assertSee('Fuera de programación');
        $this->assertSame('Fuera de programación', \App\Modules\Core\Support\Labels::enum('suspicion_reason', 'OUTSIDE_SCHEDULE'));
        $this->assertSame('Sin horario configurado', \App\Modules\Core\Support\Labels::enum('suspicion_reason', 'NO_SCHEDULE'));
    }
}
