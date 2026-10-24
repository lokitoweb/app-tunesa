<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\OffShiftAuthorization;
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

/** Marcación solo con turno programado, autorización fuera de turno desde el panel y /today */
class ScheduledPunchGateTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;

    private Branch $branch;

    private User $emp;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->seedRoles();
        $this->acme   = $this->makeTenant('acme');
        $this->branch = $this->makeBranch($this->acme);
        $this->hr     = $this->makeUser($this->acme, 'hr_manager');
        $this->emp    = $this->makeUser($this->acme, 'employee');
        $this->emp->branches()->attach($this->branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);

        // Lunes a viernes; sábado y domingo de descanso
        $shift = Shift::create(['tenant_id' => $this->acme->id, 'name' => 'Oficina', 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_period_minutes' => 10, 'daily_hours' => 8, 'working_days' => [1, 2, 3, 4, 5]]);
        EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'shift_id' => $shift->id,
            'branch_id' => $this->branch->id, 'effective_from' => '2020-01-01', 'is_active' => true]);

        TenantSetting::editableRowFor($this->acme)->fill(['punch_requires_schedule' => true])->save();
        app(TenantContext::class)->clear();
    }

    private function clockIn()
    {
        return $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', [
            'latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12,
            'device_id' => 'test-device', 'device_os' => 'android',
            'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
        ]);
    }

    public function test_rest_day_blocks_clock_in_and_today_explains_why(): void
    {
        $this->travelTo(Carbon::parse('2026-10-24 09:00', 'America/Lima')); // sábado

        $this->actingIn($this->emp, $this->acme)->getJson('/api/attendance/today')->assertOk()
            ->assertJsonPath('schedule.can_clock_in', false)
            ->assertJsonPath('schedule.reason', 'rest_day')
            ->assertJsonPath('next_action.code', 'NOT_SCHEDULED')
            ->assertJsonPath('next_action.actions', []);

        $this->clockIn()->assertStatus(422)->assertJsonPath('code', 'NOT_SCHEDULED');
        $this->assertSame(0, Attendance::withoutGlobalScopes()->count());
    }

    public function test_scheduled_day_allows_clock_in(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 08:00', 'America/Lima')); // lunes

        $this->actingIn($this->emp, $this->acme)->getJson('/api/attendance/today')
            ->assertJsonPath('schedule.can_clock_in', true)->assertJsonPath('schedule.shift.name', 'Oficina')
            ->assertJsonPath('next_action.action', 'clock_in');
        $this->clockIn()->assertCreated();
        // Segunda entrada el mismo día: no se duplica
        $this->clockIn()->assertStatus(409)->assertJsonPath('code', 'ALREADY_CLOCKED_IN');
    }

    public function test_hr_authorizes_a_rest_day_from_the_panel_and_the_person_can_punch(): void
    {
        $this->travelTo(Carbon::parse('2026-10-24 09:00', 'America/Lima')); // sábado

        $this->actingAs($this->hr)->get('/attendance-offshift')->assertOk()->assertSee('Marcación fuera de turno');
        $this->post('/attendance-offshift', ['user_id' => $this->emp->id, 'work_date' => '2026-10-24', 'reason' => 'Refuerzo por evento'])
            ->assertSessionHasNoErrors()->assertRedirect('/attendance-offshift');
        // Días pasados no: para eso está la corrección manual
        $this->post('/attendance-offshift', ['user_id' => $this->emp->id, 'work_date' => '2026-10-20', 'reason' => 'Olvido'])
            ->assertSessionHasErrors('work_date');

        $this->assertSame(1, OffShiftAuthorization::withoutGlobalScopes()->count());
        $this->assertSame(1, $this->emp->notifications()->where('type', 'shift.offshift_authorized')->count());
        app(TenantContext::class)->clear();
        $this->flushHeaders();

        $this->actingIn($this->emp, $this->acme)->getJson('/api/attendance/today')
            ->assertJsonPath('schedule.authorized', true)->assertJsonPath('schedule.can_clock_in', true);
        $this->clockIn()->assertCreated();
    }

    public function test_permissions_and_company_switch(): void
    {
        $this->travelTo(Carbon::parse('2026-10-24 09:00', 'America/Lima'));

        $this->assertContains($this->actingAs($this->emp)->get('/attendance-offshift')->status(), [302, 403]); // colaboradores no entran al panel
        $this->actingAs($this->makeUser($this->acme, 'manager'))->get('/attendance-offshift')->assertOk();
        app(TenantContext::class)->clear();

        // La empresa que no programa turnos apaga la regla
        TenantSetting::editableRowFor($this->acme)->fill(['punch_requires_schedule' => false])->save();
        $this->flushHeaders();
        $this->clockIn()->assertCreated();
    }

    public function test_manager_completes_a_forgotten_clock_out_keeping_the_breaks(): void
    {
        $gps = ['latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12, 'device_id' => 'test-device', 'device_os' => 'android'];
        $this->travelTo(Carbon::parse('2026-10-26 08:00', 'America/Lima')); // lunes
        $this->clockIn()->assertCreated();
        $this->travel(4)->hours();
        $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/break-start', $gps)->assertOk();
        $this->travel(45)->minutes();
        $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/break-end', $gps)->assertOk();
        // Fin de break dos veces: no se duplica
        $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/break-end', $gps)->assertStatus(409)->assertJsonPath('code', 'BREAK_ALREADY_ENDED');
        // Olvidó marcar la salida; al día siguiente Gerencia la completa
        $this->travelTo(Carbon::parse('2026-10-27 09:00', 'America/Lima'));
        $this->flushHeaders();
        app(TenantContext::class)->clear();

        $manager = $this->makeUser($this->acme, 'manager');
        app(TenantContext::class)->clear();
        $this->actingAs($manager)->get("/attendance-calendar/person/{$this->emp->id}/day/2026-10-26")->assertOk();
        $this->put("/attendance-calendar/person/{$this->emp->id}/day/2026-10-26", [
            'clock_in' => '08:00', 'clock_out' => '17:00', 'break_minutes' => 45, 'reason' => 'Olvidó marcar la salida (confirmado por su jefe)',
        ])->assertSessionHasNoErrors();

        $day = Attendance::withoutGlobalScopes()->where('user_id', $this->emp->id)->sole();
        $this->assertSame('2026-10-26 17:00', $day->clock_out_at->timezone('America/Lima')->format('Y-m-d H:i'));
        $this->assertNotNull($day->break_start_at);
        $this->assertNotNull($day->break_end_at);
        $this->assertSame(45, (int) $day->break_minutes);
        $this->assertSame($manager->id, (int) $day->manual_edited_by);
        $this->assertEquals(8.0, $day->regularHours());
    }
}
