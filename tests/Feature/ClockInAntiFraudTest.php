<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

class ClockInAntiFraudTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('public');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12,
            'device_id' => 'test-device', 'device_os' => 'android', 'is_mocked' => 0,
            'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
        ], $overrides);
    }

    public function test_clock_in_inside_geofence_and_rejections(): void
    {
        $acme   = $this->makeTenant('acme');
        $emp    = $this->makeUser($acme);
        $branch = $this->makeBranch($acme, ['allowed_radius_meters' => 100]);
        $emp->branches()->attach($branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);

        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->payload(['is_mocked' => 1]))
            ->assertStatus(422)->assertJsonPath('code', 'MOCK_LOCATION_DETECTED');

        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->payload(['accuracy_meters' => 450]))
            ->assertStatus(422)->assertJsonPath('code', 'GPS_ACCURACY_TOO_LOW');

        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->payload(['latitude' => -12.2]))
            ->assertStatus(422)->assertJsonPath('code', 'GEOFENCE_VIOLATION');

        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->payload())
            ->assertCreated();

        $this->actingIn($emp, $acme)->getJson('/api/attendance/today')
            ->assertOk()->assertJsonPath('next_action.action', 'break_or_clock_out');
    }
}
