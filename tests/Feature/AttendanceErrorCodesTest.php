<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Shift;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Códigos de error específicos de la marcación (AttendanceException),
 * descansos en la zona horaria de la empresa con controles anti-fraude y
 * "Refrigerio pagado" en el cálculo de horas.
 */
class AttendanceErrorCodesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('public');
        Storage::fake('private');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12,
            'device_id' => 'test-device', 'device_os' => 'android',
            'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
        ], $overrides);
    }

    private function gps(array $overrides = []): array
    {
        return array_merge(['latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12], $overrides);
    }

    private function employeeWithBranch(Tenant $tenant): User
    {
        $emp = $this->makeUser($tenant);
        $emp->branches()->attach($this->makeBranch($tenant)->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);

        return $emp;
    }

    private function assignShift(Tenant $tenant, User $emp, array $attrs = []): Shift
    {
        $shift = Shift::create(array_merge(['tenant_id' => $tenant->id, 'name' => 'Turno', 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_period_minutes' => 10, 'working_days' => [0, 1, 2, 3, 4, 5, 6]], $attrs));
        EmployeeShift::create(['tenant_id' => $tenant->id, 'user_id' => $emp->id, 'shift_id' => $shift->id,
            'branch_id' => $emp->branches()->first()->id, 'effective_from' => '2020-01-01', 'is_active' => true]);

        return $shift;
    }

    public function test_each_rule_has_its_own_code_and_status(): void
    {
        $acme = $this->makeTenant('acme');

        // Sin sucursal: ya no sale como GEOFENCE_VIOLATION
        $lonely = $this->makeUser($acme);
        $this->actingIn($lonely, $acme)->post('/api/attendance/clock-in', $this->payload())
            ->assertStatus(422)->assertJsonPath('code', 'NO_BRANCH_ASSIGNED')
            ->assertJsonPath('message', 'No tienes ninguna sucursal asignada. Contacta a RRHH.');

        $emp = $this->employeeWithBranch($acme);

        // Sin jornada abierta
        $this->actingIn($emp, $acme)->postJson('/api/attendance/break-start', $this->gps())
            ->assertStatus(404)->assertJsonPath('code', 'NO_OPEN_ATTENDANCE');
        $this->actingIn($emp, $acme)->post('/api/attendance/clock-out', $this->payload())
            ->assertStatus(404)->assertJsonPath('code', 'NO_OPEN_ATTENDANCE');

        // Geocerca: el único caso con GEOFENCE_VIOLATION (código que la app ya interpreta)
        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->payload(['latitude' => -12.2]))
            ->assertStatus(422)->assertJsonPath('code', 'GEOFENCE_VIOLATION');

        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->payload())->assertCreated();
        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->payload())
            ->assertStatus(409)->assertJsonPath('code', 'ALREADY_CLOCKED_IN');

        $this->actingIn($emp, $acme)->postJson('/api/attendance/break-end', $this->gps())
            ->assertStatus(409)->assertJsonPath('code', 'BREAK_NOT_STARTED');
        $this->actingIn($emp, $acme)->postJson('/api/attendance/break-start', $this->gps())->assertOk();
        $this->actingIn($emp, $acme)->postJson('/api/attendance/break-start', $this->gps())
            ->assertStatus(409)->assertJsonPath('code', 'BREAK_ALREADY_STARTED');
        $this->actingIn($emp, $acme)->postJson('/api/attendance/break-end', $this->gps())->assertOk();
        $this->actingIn($emp, $acme)->postJson('/api/attendance/break-end', $this->gps())
            ->assertStatus(409)->assertJsonPath('code', 'BREAK_ALREADY_ENDED');
    }

    public function test_break_not_allowed_by_shift(): void
    {
        $acme = $this->makeTenant('acme');
        $emp  = $this->employeeWithBranch($acme);
        $this->assignShift($acme, $emp, ['break_allowed' => false]);

        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->payload())->assertCreated();
        $this->actingIn($emp, $acme)->postJson('/api/attendance/break-start', $this->gps())
            ->assertStatus(422)->assertJsonPath('code', 'BREAK_NOT_ALLOWED')
            ->assertJsonPath('message', 'Tu turno no permite descansos.');
    }

    public function test_breaks_use_the_company_timezone_and_anti_fraud_checks(): void
    {
        // 10:00 en Tokio = 20:00 del día anterior en Lima (zona de la app)
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', 'Asia/Tokyo'));
        $tokyo = $this->makeTenant('tokyo', ['timezone' => 'Asia/Tokyo']);
        $emp   = $this->employeeWithBranch($tokyo);
        $this->setRow($tokyo, ['max_gps_accuracy_meters' => 30]);

        $this->actingIn($emp, $tokyo)->post('/api/attendance/clock-in', $this->payload())->assertCreated();

        // Mismos controles que la entrada: GPS simulado y precisión de Marca y app
        $this->actingIn($emp, $tokyo)->postJson('/api/attendance/break-start', $this->gps(['is_mocked' => true]))
            ->assertStatus(422)->assertJsonPath('code', 'MOCK_LOCATION_DETECTED');
        $this->actingIn($emp, $tokyo)->postJson('/api/attendance/break-start', $this->gps(['accuracy_meters' => 80]))
            ->assertStatus(422)->assertJsonPath('code', 'GPS_ACCURACY_TOO_LOW');

        $this->travel(2)->hours(); // 12:00 Tokio
        $this->actingIn($emp, $tokyo)->postJson('/api/attendance/break-start', $this->gps(['latitude' => -12.0950]))->assertOk();

        $this->travel(45)->minutes();
        $this->actingIn($emp, $tokyo)->postJson('/api/attendance/break-end', $this->gps())->assertOk();

        $attendance = Attendance::firstOrFail();
        $this->assertSame('2026-10-10', $attendance->work_date->toDateString());
        $this->assertSame('12:00:00', $attendance->break_start_at->format('H:i:s'));
        $this->assertSame('12:45:00', $attendance->break_end_at->format('H:i:s'));
        $this->assertSame(45, $attendance->break_minutes);

        // Distancia a la sucursal guardada (sin exigir geocerca) y metadatos del dispositivo
        $this->assertGreaterThan(50, $attendance->clock_in_meta['breaks']['start']['distance_meters']);
        $this->assertEquals(12, $attendance->clock_in_meta['breaks']['end']['accuracy_meters']);

        // Horas trabajadas en la zona de la empresa: 10:00 → 18:00 menos 45 min de descanso
        $this->travelTo(Carbon::parse('2026-10-10 18:00:00', 'Asia/Tokyo'));
        $this->actingIn($emp, $tokyo)->post('/api/attendance/clock-out', $this->payload())->assertOk();
        $this->assertEquals(7.25, (float) $attendance->fresh()->worked_hours);
    }

    public function test_paid_lunch_is_not_subtracted_from_worked_hours(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 08:00:00', 'America/Lima'));
        $acme = $this->makeTenant('acme');
        $emp  = $this->employeeWithBranch($acme);

        $work = function () use ($emp, $acme) {
            $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->payload())->assertCreated();
            $this->travel(4)->hours();
            $this->actingIn($emp, $acme)->postJson('/api/attendance/break-start', $this->gps())->assertOk();
            $this->travel(1)->hours();
            $this->actingIn($emp, $acme)->postJson('/api/attendance/break-end', $this->gps())->assertOk();
            $this->travel(4)->hours();
            $this->actingIn($emp, $acme)->post('/api/attendance/clock-out', $this->payload())->assertOk();

            return (float) Attendance::latest('id')->firstOrFail()->worked_hours;
        };

        // Por defecto el refrigerio no se paga: 9 h de jornada − 1 h de descanso
        $this->assertEquals(8.0, $work());

        // Heredado de la plataforma: refrigerio pagado → no se descuenta
        $this->setRow(null, ['lunch_paid' => true]);
        $this->travelTo(Carbon::parse('2026-10-10 08:00:00', 'America/Lima'));
        $this->assertEquals(9.0, $work());

        // La empresa lo desactiva explícitamente
        $this->setRow($acme, ['lunch_paid' => false]);
        $this->travelTo(Carbon::parse('2026-10-11 08:00:00', 'America/Lima'));
        $this->assertEquals(8.0, $work());
    }

    private function setRow(?Tenant $tenant, array $values): void
    {
        TenantSetting::editableRowFor($tenant)->fill($values)->save();
    }
}
