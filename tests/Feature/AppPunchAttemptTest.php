<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\AttendancePunchAttempt;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Fase 2 · intentos que la app bloquea antes de enviar (POST /api/attendance/attempts):
 * idempotentes por client_uuid, aislados por empresa, aviso a supervisor y RRHH
 * al tercer GPS falso del día y borrado a los 12 meses.
 */
class AppPunchAttemptTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;
    private User $emp;
    private User $supervisor;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->travelTo(Carbon::parse('2026-10-09 09:00:00', 'America/Lima'));

        $this->acme       = $this->makeTenant('acme');
        $this->emp        = $this->makeUser($this->acme, 'employee', ['first_name' => 'Rosa', 'last_name' => 'Quispe']);
        $this->supervisor = $this->makeUser($this->acme, 'supervisor');
        $this->branch     = $this->makeBranch($this->acme, ['name' => 'Trujillo', 'supervisor_id' => $this->supervisor->id]);
        $this->emp->branches()->attach($this->branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
    }

    private function report(array $overrides = [], ?User $user = null, ?Tenant $tenant = null)
    {
        return $this->actingIn($user ?? $this->emp, $tenant ?? $this->acme)->postJson('/api/attendance/attempts', array_merge([
            'client_uuid'     => (string) Str::uuid(),
            'type'            => 'clock_in',
            'code'            => 'GEOFENCE_VIOLATION',
            'occurred_at'     => '2026-10-09T08:58:00-05:00',
            'latitude'        => -12.0952,
            'longitude'       => -77.0356,
            'accuracy_meters' => 9,
            'distance_meters' => 198.4,
            'allowed_radius'  => 100,
            'branch_id'       => $this->branch->id,
            'device_id'       => 'pixel-7',
            'device_os'       => 'android 14',
            'app_version'     => '2.4.0',
        ], $overrides));
    }

    public function test_app_block_is_stored_once_per_client_uuid(): void
    {
        $uuid = (string) Str::uuid();
        $this->report(['client_uuid' => $uuid])->assertCreated()->assertJsonPath('duplicate', false);
        $this->report(['client_uuid' => $uuid])->assertOk()->assertJsonPath('duplicate', true);

        $attempt = AttendancePunchAttempt::withoutGlobalScopes()->sole();
        $this->assertTrue($attempt->fromApp());
        $this->assertSame($this->branch->id, $attempt->branch_id);
        $this->assertSame(198, $attempt->distance_meters);
        $this->assertSame(100, $attempt->allowed_radius);
        $this->assertSame('2026-10-09 13:58:00', $attempt->device_time->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(AttendancePunchAttempt::SOURCE_APP, $attempt->source);
    }

    public function test_validation_rejects_server_only_codes_and_stale_reports(): void
    {
        $this->report(['code' => 'FACE_NOT_DETECTED'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->report(['occurred_at' => '2026-09-20T08:00:00-05:00'])->assertUnprocessable()->assertJsonValidationErrors('occurred_at');
        $this->report(['client_uuid' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('client_uuid');
        $this->assertSame(0, AttendancePunchAttempt::withoutGlobalScopes()->count());
    }

    public function test_unassigned_branch_falls_back_to_the_primary_one_and_tenants_stay_isolated(): void
    {
        app(TenantContext::class)->clear();
        $other = $this->makeTenant('otra');
        $foreignBranch = $this->makeBranch($other);
        $intruder = $this->makeUser($other, 'employee');
        $this->app['auth']->forgetGuards();
        app(TenantContext::class)->clear();

        $this->report(['branch_id' => $foreignBranch->id])->assertCreated();
        $this->assertSame($this->branch->id, AttendancePunchAttempt::withoutGlobalScopes()->sole()->branch_id);

        // El mismo client_uuid en otra empresa es otro intento
        $uuid = AttendancePunchAttempt::withoutGlobalScopes()->sole()->client_uuid;
        $this->app['auth']->forgetGuards();
        app(TenantContext::class)->clear();
        $this->report(['client_uuid' => $uuid], $intruder, $other)->assertCreated();
        $this->assertSame(2, AttendancePunchAttempt::withoutGlobalScopes()->count());
    }

    public function test_third_mock_attempt_of_the_day_alerts_supervisor_and_hr_once(): void
    {
        $hr = $this->makeUser($this->acme, 'hr_manager');
        $mock = ['code' => 'MOCK_LOCATION_DETECTED', 'is_mocked' => true];

        $this->report($mock)->assertCreated();
        $this->report($mock)->assertCreated();
        $this->assertSame(0, $hr->notifications()->count());

        $this->report($mock)->assertCreated();
        $this->assertSame(['attendance.mock_repeated'], $hr->notifications()->pluck('type')->all());
        $this->assertSame(['attendance.mock_repeated'], $this->supervisor->notifications()->pluck('type')->all());
        $this->assertSame(0, $this->emp->notifications()->count());
        $this->assertSame(3, $hr->notifications()->first()->data['data']['attempts']);

        // El cuarto no repite el aviso; al día siguiente vuelve a contar desde cero
        $this->report($mock)->assertCreated();
        $this->assertSame(1, $hr->notifications()->count());

        $this->travelTo(Carbon::parse('2026-10-10 09:00:00', 'America/Lima'));
        $this->report($mock + ['occurred_at' => '2026-10-10T08:58:00-05:00'])->assertCreated();
        $this->assertSame(1, $hr->notifications()->count());
    }

    public function test_attempts_older_than_twelve_months_are_pruned(): void
    {
        $this->report()->assertCreated();
        $old = AttendancePunchAttempt::withoutGlobalScopes()->sole();
        AttendancePunchAttempt::withoutGlobalScopes()->whereKey($old->id)->update(['created_at' => now()->subMonths(13)]);
        $this->report()->assertCreated();

        $this->artisan('model:prune', ['--model' => [AttendancePunchAttempt::class]])->assertSuccessful();

        $this->assertSame(1, AttendancePunchAttempt::withoutGlobalScopes()->count());
        $this->assertNull(AttendancePunchAttempt::withoutGlobalScopes()->find($old->id));
    }

    public function test_panel_shows_app_badge_and_map(): void
    {
        $this->report(['code' => 'MOCK_LOCATION_DETECTED', 'is_mocked' => true])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $this->actingAs($this->makeUser($this->acme, 'hr_manager'))->get(route('attendance.attempts'))->assertOk()
            ->assertSee('Desde la app')->assertSee('id="attemptsMap"', false)
            ->assertSee('La app detectó una ubicación simulada');
    }
}
