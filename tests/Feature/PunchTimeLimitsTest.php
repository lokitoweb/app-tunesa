<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Requerimiento Tunesa: no se marca antes de la hora del horario. Entrada desde 1 min antes,
 * fin del refrigerio 1 min antes de completarlo, salida desde la hora exacta; para salir antes,
 * un permiso aprobado. Todo administrable en Configuración › Reglas.
 */
class PunchTimeLimitsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;

    private User $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('public');
        Storage::fake('private');

        $this->acme = $this->makeTenant('acme');
        $this->emp  = $this->makeUser($this->acme);
        $branch = $this->makeBranch($this->acme);
        $this->emp->branches()->attach($branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $shift = Shift::create(['tenant_id' => $this->acme->id, 'name' => 'Oficina', 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_period_minutes' => 10, 'break_allowed' => true, 'break_duration_minutes' => 60, 'working_days' => [0, 1, 2, 3, 4, 5, 6]]);
        EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'shift_id' => $shift->id,
            'branch_id' => $branch->id, 'effective_from' => '2020-01-01', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): void
    {
        $this->travelTo(Carbon::parse("2026-11-20 {$time}:00", 'America/Lima'));
    }

    private function punch(string $type)
    {
        $gps = ['latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12];

        return in_array($type, ['break-start', 'break-end'], true)
            ? $this->actingIn($this->emp, $this->acme)->postJson("/api/attendance/{$type}", $gps)
            : $this->actingIn($this->emp, $this->acme)->post("/api/attendance/{$type}", $gps + [
                'device_id' => 'test-device', 'device_os' => 'android', 'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640)]);
    }

    public function test_entry_break_and_exit_are_not_marked_before_their_time(): void
    {
        $this->at('07:58');
        $this->punch('clock-in')->assertStatus(422)->assertJsonPath('code', 'CLOCK_IN_TOO_EARLY')
            ->assertJsonPath('message', 'Aún no es tu hora de entrada: podrás marcar desde las 07:59 am.');

        $this->at('07:59');
        $this->punch('clock-in')->assertCreated();

        $this->at('12:00');
        $this->punch('break-start')->assertOk();
        $this->at('12:58');
        $this->punch('break-end')->assertStatus(422)->assertJsonPath('code', 'BREAK_TOO_SHORT');
        $this->at('12:59');
        $this->punch('break-end')->assertOk();

        // La app sabe desde qué hora puede salir (y avisa antes de pedir la selfie)
        $this->at('16:59');
        $this->actingIn($this->emp, $this->acme)->getJson('/api/attendance/today')
            ->assertJsonPath('clock_out_allowed_from', Carbon::parse('2026-11-20 17:00:00', 'America/Lima')->toIso8601String());
        $this->punch('clock-out')->assertStatus(422)->assertJsonPath('code', 'CLOCK_OUT_TOO_EARLY');

        $this->at('17:00');
        $this->punch('clock-out')->assertOk();

        // Horas desde las 8:00 (no desde las 7:59) y con el refrigerio completo descontado: 9 h − 1 h
        $day = Attendance::firstOrFail();
        $this->assertEquals(8.0, (float) $day->worked_hours);
        $this->assertEquals(0.0, (float) $day->overtime_hours);
        $this->assertSame(0, (int) $day->late_minutes);
    }

    public function test_an_approved_leave_for_the_day_lets_them_leave_early(): void
    {
        $this->at('08:00');
        $this->punch('clock-in')->assertCreated();

        $leave = LeaveRequest::withoutGlobalScopes()->create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id,
            'type' => 'permission', 'start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'start_time' => '15:00', 'end_time' => '17:00',
            'days' => 0, 'reason' => 'Cita médica', 'status' => 'pending']);

        $this->at('15:00');
        $this->punch('clock-out')->assertStatus(422)->assertJsonPath('code', 'CLOCK_OUT_TOO_EARLY'); // pendiente: aún no

        $leave->update(['status' => 'approved']);
        $this->actingIn($this->emp, $this->acme)->getJson('/api/attendance/today')->assertJsonPath('clock_out_allowed_from', null);
        $this->punch('clock-out')->assertOk();
    }

    public function test_margins_are_configurable_and_the_rule_can_be_turned_off(): void
    {
        TenantSetting::editableRowFor($this->acme)->fill(['clock_in_early_minutes' => 15, 'clock_out_early_minutes' => 5])->save();

        $this->at('07:44');
        $this->punch('clock-in')->assertStatus(422)->assertJsonPath('code', 'CLOCK_IN_TOO_EARLY');
        $this->at('07:45');
        $this->punch('clock-in')->assertCreated();
        $this->at('16:55');
        $this->punch('clock-out')->assertOk();

        // Apagado: entrada y salida libres (como antes)
        TenantSetting::editableRowFor($this->acme)->fill(['punch_time_limits' => false])->save();
        $this->travelTo(Carbon::parse('2026-11-21 07:00:00', 'America/Lima'));
        $this->punch('clock-in')->assertCreated();
        $this->travelTo(Carbon::parse('2026-11-21 15:00:00', 'America/Lima'));
        $this->punch('clock-out')->assertOk();
    }
}
