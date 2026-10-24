<?php

namespace Tests\Feature;

use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Shift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * DEF-04: la salida tiene las mismas reglas que la entrada (geocerca, GPS
 * falso, precisión) y la app recibe la hora de salida esperada del turno de
 * ese día para avisar de una jornada incompleta.
 */
class ClockOutControlTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12,
            'device_id' => 'test-device', 'device_os' => 'android', 'is_mocked' => 0,
            'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
        ], $overrides);
    }

    public function test_clock_out_follows_entry_rules_and_reports_the_expected_end_of_that_day(): void
    {
        $this->seedRoles();
        Storage::fake('public');
        Storage::fake('private');

        $acme = $this->makeTenant('acme');
        $emp = $this->makeUser($acme);
        $branch = $this->makeBranch($acme, ['allowed_radius_meters' => 100]);
        $emp->branches()->attach($branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $shift = Shift::create([
            'tenant_id' => $acme->id, 'name' => 'Oficina', 'type' => 'fixed', 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_period_minutes' => 10, 'break_allowed' => true, 'break_duration_minutes' => 60, 'daily_hours' => 8,
            'working_days' => [1, 2, 3, 4, 5, 6], 'color' => '#0284C7', 'is_active' => true,
            'day_schedules' => ['6' => ['start_time' => '08:00', 'end_time' => '13:00', 'break_duration_minutes' => 0, 'daily_hours' => 5]],
        ]);
        EmployeeShift::create([
            'tenant_id' => $acme->id, 'user_id' => $emp->id, 'shift_id' => $shift->id, 'branch_id' => $branch->id,
            'effective_from' => '2026-01-01', 'is_active' => true,
        ]);

        // Lunes: sale a las 17:00
        $this->travelTo(Carbon::parse('2026-10-12 08:02', 'America/Lima'));
        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->payload())->assertCreated();
        $this->actingIn($emp, $acme)->getJson('/api/attendance/today')->assertOk()
            ->assertJsonPath('expected_clock_out', '2026-10-12T17:00:00-05:00');

        // Fuera de la sede no se marca la salida (igual que la entrada)
        $this->travelTo(Carbon::parse('2026-10-12 15:00', 'America/Lima'));
        $this->actingIn($emp, $acme)->post('/api/attendance/clock-out', $this->payload(['latitude' => -12.2]))
            ->assertStatus(422)->assertJsonPath('code', 'GEOFENCE_VIOLATION');
        $this->actingIn($emp, $acme)->post('/api/attendance/clock-out', $this->payload(['is_mocked' => 1]))
            ->assertStatus(422)->assertJsonPath('code', 'MOCK_LOCATION_DETECTED');

        $this->actingIn($emp, $acme)->post('/api/attendance/clock-out', $this->payload())->assertOk();
        $this->actingIn($emp, $acme)->getJson('/api/attendance/today')->assertJsonPath('expected_clock_out', null);

        // Sábado corto: sale a las 13:00
        $this->travelTo(Carbon::parse('2026-10-17 08:00', 'America/Lima'));
        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->payload())->assertCreated();
        $this->actingIn($emp, $acme)->getJson('/api/attendance/today')
            ->assertJsonPath('expected_clock_out', '2026-10-17T13:00:00-05:00');
    }
}
