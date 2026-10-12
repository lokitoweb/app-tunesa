<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\AttendancePunchAttempt;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Intentos de marcación rechazados: se guardan para RRHH (attendance_punch_attempts)
 * y se ven en el panel en «Intentos rechazados».
 */
class PunchAttemptLogTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;

    private User $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('private');
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['display_name' => 'Lima'])]);
        $this->travelTo(Carbon::parse('2026-10-09 09:00:00', 'America/Lima'));

        $this->acme = $this->makeTenant('acme');
        $this->emp  = $this->makeUser($this->acme, 'employee', ['first_name' => 'Rosa', 'last_name' => 'Quispe']);
        $this->emp->branches()->attach($this->makeBranch($this->acme, ['name' => 'Terminal Plaza Norte'])->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        TenantSetting::editableRowFor($this->acme)->fill(['selfie_required' => false, 'offline_mode_enabled' => true, 'offline_max_hours' => 24])->save();
    }

    private function gps(array $overrides = []): array
    {
        return array_merge([
            'latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12,
            'device_id' => 'pixel-7', 'device_os' => 'android', 'app_version' => '2.3.0',
        ], $overrides);
    }

    public function test_geofence_rejection_is_logged_with_distance_and_device(): void
    {
        $this->actingIn($this->emp, $this->acme)
            ->withHeaders(['X-Device-Integrity' => 'rooted'])
            ->postJson('/api/attendance/clock-in', $this->gps(['latitude' => -12.2]))
            ->assertStatus(422)->assertJsonPath('code', 'GEOFENCE_VIOLATION');

        $attempt = AttendancePunchAttempt::withoutGlobalScopes()->sole();
        $this->assertSame($this->acme->id, $attempt->tenant_id);
        $this->assertSame($this->emp->id, $attempt->user_id);
        $this->assertNotNull($attempt->branch_id);
        $this->assertSame('clock_in', $attempt->type);
        $this->assertSame('GEOFENCE_VIOLATION', $attempt->error_code);
        $this->assertGreaterThan(10000, $attempt->distance_meters);
        $this->assertSame(100, $attempt->allowed_radius);
        $this->assertSame(['rooted'], $attempt->device_flags);
        $this->assertSame('android', $attempt->device_os);
        $this->assertSame('2.3.0', $attempt->app_version);
        $this->assertFalse($attempt->offline);
    }

    public function test_other_rule_rejections_are_logged_but_state_conflicts_are_not(): void
    {
        $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/clock-in', $this->gps(['is_mocked' => true]))
            ->assertStatus(422)->assertJsonPath('code', 'MOCK_LOCATION_DETECTED');
        $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/clock-in', $this->gps(['accuracy_meters' => 900]))
            ->assertStatus(422)->assertJsonPath('code', 'GPS_ACCURACY_TOO_LOW');

        // Sin jornada abierta / ya marcó: son reintentos, no intentos rechazados
        $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/break-start', $this->gps())->assertStatus(404);
        $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/clock-in', $this->gps())->assertCreated();
        $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/clock-in', $this->gps())->assertStatus(409);

        $this->assertSame(['MOCK_LOCATION_DETECTED', 'GPS_ACCURACY_TOO_LOW'],
            AttendancePunchAttempt::withoutGlobalScopes()->orderBy('id')->pluck('error_code')->all());
        $this->assertTrue(AttendancePunchAttempt::withoutGlobalScopes()->first()->mock_location_detected);
    }

    public function test_offline_batch_rejections_are_logged_as_offline(): void
    {
        $at = '2026-10-07T08:00:00-05:00';
        $this->actingIn($this->emp, $this->acme)->post('/api/v1/attendances/sync-batch', ['punches' => [
            $this->gps(['type' => 'clock_in', 'client_uuid' => $uuid = (string) Str::uuid(), 'offline_recorded_at' => $at, 'device_time' => $at]),
        ]])->assertOk()->assertJsonPath('data.results.0.code', 'OFFLINE_TOO_OLD');

        $attempt = AttendancePunchAttempt::withoutGlobalScopes()->sole();
        $this->assertTrue($attempt->offline);
        $this->assertSame($uuid, $attempt->client_uuid);
        $this->assertSame('2026-10-07 13:00:00', $attempt->offline_recorded_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_panel_lists_attempts_with_filters_only_for_its_company(): void
    {
        app(TenantContext::class)->clear();
        $other = $this->makeTenant('otra');
        $intruder = $this->makeUser($other, 'employee', ['first_name' => 'Ajeno']);
        $intruder->branches()->attach($this->makeBranch($other)->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        TenantSetting::editableRowFor($other)->fill(['selfie_required' => false])->save();

        $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/clock-in', $this->gps(['latitude' => -12.2]))->assertStatus(422);
        $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/clock-in', $this->gps(['accuracy_meters' => 900]))->assertStatus(422);
        $this->app['auth']->forgetGuards();
        app(TenantContext::class)->clear();
        $this->actingIn($intruder, $other)->postJson('/api/attendance/clock-in', $this->gps(['latitude' => -12.2]))->assertStatus(422);
        $this->app['auth']->forgetGuards();
        app(TenantContext::class)->clear();
        $this->assertSame(3, AttendancePunchAttempt::withoutGlobalScopes()->count());

        $hr = $this->makeUser($this->acme, 'hr_manager');
        $this->actingAs($hr)->get(route('attendance.attempts'))->assertOk()
            ->assertSee('Intentos rechazados')->assertSee('Rosa')->assertDontSee('Ajeno')
            ->assertSee('Fuera de la geocerca')->assertSee('GPS impreciso')->assertSee('Ver en el mapa');

        $this->actingAs($hr)->get(route('attendance.attempts', ['code' => 'GPS_ACCURACY_TOO_LOW']))->assertOk()
            ->assertSee('La precisión del GPS es baja')->assertDontSee('m permitidos');

        $this->actingAs($hr)->get(route('attendance.attempts', ['start_date' => '2026-10-01', 'end_date' => '2026-10-02']))->assertOk()
            ->assertSee('No hubo intentos rechazados en este rango.');

        $this->actingAs($this->makeUser($this->acme, 'supervisor'))->get(route('attendance.attempts'))->assertForbidden();
    }
}
