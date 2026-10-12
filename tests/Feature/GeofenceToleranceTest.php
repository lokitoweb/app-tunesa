<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\CompanyConfiguration;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Fase 2 · geocerca con tolerancia: zona de borde según la precisión del GPS,
 * ajustes por sede y señales de ubicación inventada (viaje imposible,
 * coordenadas repetidas).
 *
 * La sede está en -12.09418, -77.03560 con radio 100 m; 0.001° de latitud ≈ 111 m.
 */
class GeofenceToleranceTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;
    private User $emp;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('public');
        Storage::fake('private');

        $this->acme   = $this->makeTenant('acme');
        $this->emp    = $this->makeUser($this->acme);
        $this->branch = $this->makeBranch($this->acme, ['allowed_radius_meters' => 100]);
        $this->emp->branches()->attach($this->branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $this->travelTo(Carbon::parse('2026-10-09 08:00:00', 'America/Lima'));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12,
            'device_id' => 'test-device', 'device_os' => 'android', 'is_mocked' => 0,
            'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
        ], $overrides);
    }

    private function clockIn(array $overrides = [])
    {
        return $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->payload($overrides));
    }

    public function test_edge_zone_depends_on_gps_accuracy_and_branch_tolerance(): void
    {
        // ~111 m con ±5 m: el margen es 5 m, sigue fuera → rechazo (política de la empresa)
        $this->clockIn(['latitude' => -12.09318, 'accuracy_meters' => 5])
            ->assertStatus(422)->assertJsonPath('code', 'GEOFENCE_VIOLATION');

        // ~111 m con ±40 m: el margen se limita a la tolerancia (15 m) → borde, aceptada observada
        $this->clockIn(['latitude' => -12.09318, 'accuracy_meters' => 40])
            ->assertCreated()
            ->assertJsonPath('punch.suspicion_reasons', ['GEOFENCE_EDGE'])
            ->assertJsonPath('punch.within_geofence', false);
    }

    public function test_edge_never_reaches_beyond_the_tolerance(): void
    {
        // ~133 m con ±40 m: 133 > 100 + 15 → rechazo
        $this->clockIn(['latitude' => -12.09298, 'accuracy_meters' => 40])
            ->assertStatus(422)->assertJsonPath('code', 'GEOFENCE_VIOLATION');

        // Tolerancia 0 en la sede: sin zona de borde
        $this->branch->update(['geofence_tolerance_meters' => 0]);
        $this->clockIn(['latitude' => -12.09318, 'accuracy_meters' => 40])
            ->assertStatus(422)->assertJsonPath('code', 'GEOFENCE_VIOLATION');
    }

    public function test_branch_policy_overrides_the_company_policy(): void
    {
        $this->assertFalse(CompanyConfiguration::forTenantId($this->acme->id)->flagsOutOfGeofence());

        $this->branch->update(['geofence_policy' => 'flag']);
        $this->clockIn(['latitude' => -12.0922])
            ->assertCreated()->assertJsonPath('punch.suspicion_reasons', ['OUT_OF_GEOFENCE']);
    }

    public function test_branch_max_gps_accuracy_overrides_the_company_value(): void
    {
        // Empresa: 100 m (valor de fábrica)
        $this->clockIn(['accuracy_meters' => 120])
            ->assertStatus(422)->assertJsonPath('code', 'GPS_ACCURACY_TOO_LOW');

        $this->branch->update(['max_gps_accuracy_meters' => 150]);
        $this->clockIn(['accuracy_meters' => 120])->assertCreated();
    }

    public function test_impossible_travel_is_flagged(): void
    {
        $this->emp->update(['roaming_attendance_enabled' => true]);
        $this->clockIn()->assertCreated()->assertJsonPath('punch.suspicious', false);

        // 10 min después a ~44 km: más de 150 km/h
        $this->travelTo(Carbon::parse('2026-10-09 08:10:00', 'America/Lima'));
        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-out', $this->payload(['latitude' => -12.49]))
            ->assertOk()->assertJsonPath('punch.suspicion_reasons', ['IMPOSSIBLE_TRAVEL']);
    }

    public function test_short_moves_are_not_impossible_travel(): void
    {
        $this->clockIn()->assertCreated();

        // 1 min después a ~50 m: ruido de GPS, no un viaje
        $this->travelTo(Carbon::parse('2026-10-09 08:01:00', 'America/Lima'));
        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-out', $this->payload(['latitude' => -12.09373]))
            ->assertOk()->assertJsonPath('punch.suspicious', false);
    }

    public function test_identical_coordinates_on_another_day_are_flagged(): void
    {
        $this->clockIn(['latitude' => -12.0941812, 'longitude' => -77.0356034])->assertCreated();

        // Otro día, mismas coordenadas exactas
        $this->travelTo(Carbon::parse('2026-10-10 08:00:00', 'America/Lima'));
        $this->clockIn(['latitude' => -12.0941812, 'longitude' => -77.0356034])
            ->assertCreated()->assertJsonPath('punch.suspicion_reasons', ['REPEATED_COORDINATES']);

        // Otro día, otra lectura real (difiere en centímetros): sin bandera
        $this->travelTo(Carbon::parse('2026-10-11 08:00:00', 'America/Lima'));
        $this->clockIn(['latitude' => -12.0941847, 'longitude' => -77.0356012])
            ->assertCreated()->assertJsonPath('punch.suspicious', false);
    }

    public function test_my_branches_exposes_effective_tolerance_and_accuracy(): void
    {
        $this->actingIn($this->emp, $this->acme)->getJson('/api/my-branches')
            ->assertOk()
            ->assertJsonPath('data.0.geofence.tolerance_meters', Branch::DEFAULT_EDGE_TOLERANCE)
            ->assertJsonPath('data.0.geofence.max_gps_accuracy_meters', 100);

        $this->branch->update(['geofence_tolerance_meters' => 25, 'max_gps_accuracy_meters' => 60]);
        $this->actingIn($this->emp, $this->acme)->getJson('/api/my-branches')
            ->assertJsonPath('data.0.geofence.tolerance_meters', 25)
            ->assertJsonPath('data.0.geofence.max_gps_accuracy_meters', 60);
    }

    public function test_hr_sets_branch_geofence_tuning_from_the_panel(): void
    {
        $hr = $this->makeUser($this->acme, 'hr_manager');
        $this->flushHeaders();
        $this->actingAs($hr);

        $this->get("/branches/{$this->branch->id}/edit")->assertOk()
            ->assertSee('Ajustes de geocerca de esta sede')->assertSee('name="geofence_tolerance_meters"', false);

        $base = [
            'name' => $this->branch->name, 'address' => 'Av. César Vallejo 1388', 'latitude' => -8.11823, 'longitude' => -79.02052,
            'allowed_radius_meters' => 100,
        ];

        $this->put("/branches/{$this->branch->id}", $base + ['geofence_tolerance_meters' => 500])
            ->assertSessionHasErrors('geofence_tolerance_meters');

        $this->put("/branches/{$this->branch->id}", $base + [
            'geofence_tolerance_meters' => 10, 'max_gps_accuracy_meters' => 50, 'geofence_policy' => 'flag',
        ])->assertRedirect(route('branches.index'));

        $fresh = $this->branch->fresh();
        $this->assertSame(10, $fresh->geofence_tolerance_meters);
        $this->assertSame(50, $fresh->max_gps_accuracy_meters);
        $this->assertTrue($fresh->flagsOutOfGeofence());

        // Vacío vuelve a usar el valor de la empresa
        $this->put("/branches/{$this->branch->id}", $base + ['geofence_tolerance_meters' => '', 'geofence_policy' => ''])
            ->assertRedirect(route('branches.index'));
        $this->assertNull($this->branch->fresh()->geofence_tolerance_meters);
        $this->assertFalse($this->branch->fresh()->flagsOutOfGeofence());
    }
}
