<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendancePunch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Panel de marcaciones: la bitácora inmutable y las sospechosas se muestran
 * con etiquetas en español (nunca códigos como MOCK_LOCATION o clock_in).
 */
class AttendancePanelIntegrityTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_show_lists_the_punch_log_and_index_filters_suspicious(): void
    {
        $acme   = $this->makeTenant('acme');
        $admin  = $this->makeUser($acme, 'company_admin');
        $emp    = $this->makeUser($acme, 'employee', ['first_name' => 'Rosa', 'last_name' => 'Flagged']);
        $clean  = $this->makeUser($acme, 'employee', ['first_name' => 'Pedro', 'last_name' => 'Clean']);
        $branch = $this->makeBranch($acme);
        $today  = now()->toDateString();

        $suspicious = Attendance::create(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'branch_id' => $branch->id,
            'work_date' => $today, 'clock_in_at' => now(), 'status' => 'present', 'is_suspicious' => true]);
        Attendance::create(['tenant_id' => $acme->id, 'user_id' => $clean->id, 'branch_id' => $branch->id,
            'work_date' => $today, 'clock_in_at' => now(), 'status' => 'present']);

        AttendancePunch::create([
            'tenant_id' => $acme->id, 'attendance_id' => $suspicious->id, 'user_id' => $emp->id, 'branch_id' => $branch->id,
            'type' => 'clock_in', 'client_uuid' => (string) Str::uuid(), 'latitude' => -12.0464, 'longitude' => -77.0428,
            'accuracy_meters' => 12.4, 'distance_meters' => 340, 'within_geofence' => false, 'address' => 'Av. Abancay 123, Lima',
            'device_time' => now()->subSeconds(400), 'server_time' => now(), 'time_drift_seconds' => -400,
            'offline' => false, 'face_detected' => true, 'mock_location_detected' => true, 'suspicious' => true,
            'suspicion_reasons' => ['MOCK_LOCATION', 'TIME_DRIFT'],
        ]);

        $this->actingAs($admin)->get("/attendance/{$suspicious->id}")->assertOk()
            ->assertSee('Bitácora de marcaciones')->assertSee('Sospechosa')
            ->assertSee('Ubicación simulada')->assertSee('Hora del celular desfasada')
            ->assertSee('Av. Abancay 123, Lima')->assertSee('± 12 m')->assertSee('400 s')
            ->assertDontSee('MOCK_LOCATION')->assertDontSee('TIME_DRIFT')->assertDontSee('clock_in<', false);

        $this->actingAs($admin)->get('/attendance?status=suspicious')->assertOk()
            ->assertSee('Flagged')->assertDontSee('Clean');
    }
}
