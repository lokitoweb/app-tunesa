<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\CompanyConfiguration;
use App\Modules\Attendance\Services\GeofenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Geocercas: radio por sucursal (alta desde la vista global del Super Admin)
 * y política de la empresa ante marcaciones fuera del radio.
 */
class GeofenceBranchTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('public');
        Storage::fake('private');
    }

    private function payload(float $latitude): array
    {
        return [
            'latitude' => $latitude, 'longitude' => -77.03560, 'accuracy_meters' => 12,
            'device_id' => 'test-device', 'device_os' => 'android', 'is_mocked' => 0,
            'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
        ];
    }

    public function test_haversine_distance_matches_a_known_reference(): void
    {
        $geo = app(GeofenceService::class);

        // 0.001° de latitud ≈ 111.2 m en cualquier parte del globo
        $this->assertEqualsWithDelta(111.2, $geo->calculateDistance(-12.0, -77.0, -12.001, -77.0), 0.5);
        // Lima Centro → Miraflores ≈ 8.3 km
        $this->assertEqualsWithDelta(8300, $geo->calculateDistance(-12.046374, -77.042793, -12.121, -77.030), 150);
    }

    public function test_company_policy_rejects_or_flags_clock_in_outside_the_radius(): void
    {
        $acme   = $this->makeTenant('acme');
        $emp    = $this->makeUser($acme);
        $branch = $this->makeBranch($acme, ['allowed_radius_meters' => 100]);
        $emp->branches()->attach($branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $farAway = (float) $branch->latitude - 0.01; // ~1.1 km

        // Por defecto: rechazar
        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->payload($farAway))
            ->assertStatus(422)->assertJsonPath('code', 'GEOFENCE_VIOLATION');
        $this->assertSame(0, Attendance::withoutGlobalScopes()->count());

        // Política "registrar como incidencia": entra, marcada fuera de geocerca y auditada
        CompanyConfiguration::forTenantId($acme->id)->fill(['geofence_policy' => 'flag'])->save();

        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->payload($farAway))
            ->assertCreated()->assertJsonPath('data.clock_in.within_geofence', false);

        $attendance = Attendance::withoutGlobalScopes()->firstOrFail();
        $this->assertFalse($attendance->clock_in_within_geofence);
        $this->assertGreaterThan(1000, $attendance->clock_in_distance_meters);
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'attendance.clock_in_out_of_geofence')->exists());
    }

    public function test_hr_saves_the_geofence_policy_from_the_settings_page(): void
    {
        $acme = $this->makeTenant('acme');
        $hr   = $this->makeUser($acme, 'company_admin');

        $this->actingAs($hr)->get('/settings/mobile')->assertOk()->assertSee('Marcación fuera de geocerca');
        $this->actingAs($hr)->put('/settings/mobile', ['max_upload_mb' => 5, 'geofence_policy' => 'flag'])->assertRedirect();
        $this->assertSame('flag', CompanyConfiguration::forTenantId($acme->id)->geofence_policy);

        $this->actingAs($hr)->put('/settings/mobile', ['max_upload_mb' => 5, 'geofence_policy' => 'otra'])
            ->assertSessionHasErrors('geofence_policy');
    }

    public function test_super_admin_creates_and_edits_a_branch_for_a_chosen_company_from_global_view(): void
    {
        $acme  = $this->makeTenant('acme', ['company_name' => 'Acme SAC']);
        $other = $this->makeTenant('other', ['company_name' => 'Other SRL']);
        $root  = $this->makeUser(null, 'super_admin');
        $acmeSup  = $this->makeUser($acme, 'supervisor', ['first_name' => 'Sofia']);
        $otherSup = $this->makeUser($other, 'supervisor', ['first_name' => 'Bruno']);

        $this->actingAs($root);

        $this->get('/branches/create')->assertOk()
            ->assertSee('Elige la empresa')->assertSee('Acme SAC')->assertSee('Other SRL')
            ->assertSee('id="radiusInput"', false)->assertSee('data-tenant="' . $acme->id . '"', false);

        $branch = [
            'name' => 'Obra Norte', 'address' => 'Av. Norte 1', 'latitude' => -12.01, 'longitude' => -77.05,
            'allowed_radius_meters' => 1500,
        ];

        // El supervisor debe ser de la empresa elegida
        $this->post('/branches', $branch + ['tenant_id' => $acme->id, 'supervisor_id' => $otherSup->id])
            ->assertSessionHasErrors('supervisor_id');

        $this->post('/branches', $branch + ['tenant_id' => $acme->id, 'supervisor_id' => $acmeSup->id])
            ->assertRedirect(route('branches.index'));

        $created = Branch::withoutGlobalScopes()->where('name', 'Obra Norte')->firstOrFail();
        $this->assertSame($acme->id, $created->tenant_id);
        $this->assertSame(1500, (int) $created->allowed_radius_meters);

        // Editar desde la vista global usa la empresa de la propia sucursal
        $this->get("/branches/{$created->id}/edit")->assertOk()->assertSee('Acme SAC')->assertSee('Sofia')->assertDontSee('Bruno');
        $this->put("/branches/{$created->id}", array_merge($branch, ['allowed_radius_meters' => 80]))->assertRedirect(route('branches.index'));
        $this->assertSame(80, (int) $created->fresh()->allowed_radius_meters);
    }
}
