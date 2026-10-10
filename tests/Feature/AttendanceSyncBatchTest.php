<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** POST /api/v1/attendances/sync-batch */
class AttendanceSyncBatchTest extends TestCase
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
        $this->travelTo(Carbon::parse('2026-10-09 18:00:00', 'America/Lima'));

        $this->acme = $this->makeTenant('acme');
        $this->emp  = $this->makeUser($this->acme);
        $this->emp->branches()->attach($this->makeBranch($this->acme)->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        TenantSetting::editableRowFor($this->acme)->fill([
            'offline_mode_enabled' => true, 'offline_max_hours' => 24, 'selfie_required' => false,
        ])->save();
    }

    private function item(string $type, string $at, array $extra = []): array
    {
        return array_merge([
            'type' => $type, 'client_uuid' => (string) Str::uuid(),
            'latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 8,
            'offline_recorded_at' => $at, 'device_time' => $at, 'device_os' => 'ios', 'app_version' => '2.0.0',
        ], $extra);
    }

    public function test_mixed_batch_is_processed_chronologically_and_answers_in_request_order(): void
    {
        // Enviadas desordenadas a propósito
        $out    = $this->item('clock_out', '2026-10-09T17:05:00-05:00', ['photo' => UploadedFile::fake()->image('out.jpg', 200, 200)]);
        $bEnd   = $this->item('break_end', '2026-10-09T13:00:00-05:00');
        $in     = $this->item('clock_in', '2026-10-09T08:01:00-05:00', ['photo' => UploadedFile::fake()->image('in.jpg', 200, 200)]);
        $bStart = $this->item('break_start', '2026-10-09T12:00:00-05:00');
        $tooOld = $this->item('break_start', '2026-10-07T12:00:00-05:00');
        $bad    = $this->item('clock_in', '2026-10-09T08:00:00-05:00', ['latitude' => 'norte']);
        $noUuid = $this->item('break_end', '2026-10-09T13:10:00-05:00', ['client_uuid' => '']);

        $res = $this->actingIn($this->emp, $this->acme)->post('/api/v1/attendances/sync-batch', [
            'device_sent_at' => now()->utc()->addSeconds(30)->toIso8601String(),
            'punches'        => [$out, $bEnd, $in, $bStart, $tooOld, $bad, $noUuid],
        ])->assertOk()->assertJsonPath('success', true);

        $results = $res->json('data.results');
        $this->assertSame([$out['client_uuid'], $bEnd['client_uuid'], $in['client_uuid'], $bStart['client_uuid'], $tooOld['client_uuid'], $bad['client_uuid'], null],
            array_column($results, 'client_uuid'));
        $this->assertSame(['created', 'created', 'created', 'created', 'rejected', 'rejected', 'rejected'], array_column($results, 'status'));
        $this->assertSame([null, null, null, null, 'OFFLINE_TOO_OLD', 'VALIDATION_ERROR', 'VALIDATION_ERROR'], array_column($results, 'code'));
        $this->assertSame(['created' => 4, 'duplicates' => 0, 'rejected' => 3], $res->json('meta'));

        $attendance = Attendance::withoutGlobalScopes()->sole();
        $this->assertSame($attendance->id, $results[0]['attendance_id']);
        $this->assertNull($results[4]['attendance_id']);
        $this->assertNull($results[4]['punch_id']);
        $this->assertSame('08:01:00', $attendance->clock_in_at->format('H:i:s'));
        $this->assertSame('17:05:00', $attendance->clock_out_at->format('H:i:s'));
        $this->assertSame(60, (int) $attendance->break_minutes);
        $this->assertNotNull($attendance->clock_in_photo);
        $this->assertNotNull($attendance->clock_out_photo);

        // Bitácora en orden cronológico, todas offline, desfase medido con device_sent_at
        $punches = AttendancePunch::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertSame(['clock_in', 'break_start', 'break_end', 'clock_out'], $punches->pluck('type')->all());
        $this->assertTrue($punches->every->offline);
        $this->assertSame([30], $punches->pluck('time_drift_seconds')->unique()->values()->all());
        $this->assertSame($punches[3]->id, $results[0]['punch_id']);
    }

    public function test_resending_the_batch_returns_duplicates_without_creating_anything(): void
    {
        $items = [
            $this->item('clock_in', '2026-10-09T08:00:00-05:00'),
            $this->item('clock_out', '2026-10-09T17:00:00-05:00'),
        ];

        $this->actingIn($this->emp, $this->acme)->post('/api/attendances/sync-batch', ['punches' => $items])
            ->assertOk()->assertJsonPath('meta.created', 2);

        $again = $this->actingIn($this->emp, $this->acme)->post('/api/attendances/sync-batch', ['punches' => $items])
            ->assertOk()->assertJsonPath('meta', ['created' => 0, 'duplicates' => 2, 'rejected' => 0]);

        $this->assertSame(['duplicate', 'duplicate'], array_column($again->json('data.results'), 'status'));
        $this->assertSame(2, AttendancePunch::withoutGlobalScopes()->count());

        // El mismo uuid repetido dentro de un lote: el segundo es duplicado
        $b = $this->item('break_start', '2026-10-09T12:00:00-05:00');
        $this->actingIn($this->emp, $this->acme)->post('/api/attendances/sync-batch', ['punches' => [$b, $b]])
            ->assertOk()->assertJsonPath('meta.created', 0)->assertJsonPath('meta.rejected', 2); // jornada ya cerrada
    }

    public function test_offline_window_and_permission_are_checked_per_item(): void
    {
        TenantSetting::editableRowFor($this->acme)->fill(['offline_mode_enabled' => false])->save();

        $res = $this->actingIn($this->emp, $this->acme)->post('/api/v1/attendances/sync-batch', ['punches' => [
            $this->item('clock_in', '2026-10-09T08:00:00-05:00'),
            $this->item('clock_in', '', ['offline_recorded_at' => null, 'device_time' => null]), // en línea: se acepta
        ]])->assertOk();

        $this->assertSame(['OFFLINE_NOT_ALLOWED', null], array_column($res->json('data.results'), 'code'));
    }

    public function test_selfie_required_applies_to_clock_items_only(): void
    {
        TenantSetting::editableRowFor($this->acme)->fill(['selfie_required' => true])->save();

        $res = $this->actingIn($this->emp, $this->acme)->post('/api/v1/attendances/sync-batch', ['punches' => [
            $this->item('clock_in', '2026-10-09T08:00:00-05:00'),
            $this->item('clock_in', '2026-10-09T08:00:30-05:00', ['photo' => UploadedFile::fake()->image('s.jpg', 100, 100)]),
            $this->item('break_start', '2026-10-09T12:00:00-05:00'),
        ]])->assertOk();

        $this->assertSame(['rejected', 'created', 'created'], array_column($res->json('data.results'), 'status'));
        $this->assertSame('La foto selfie es obligatoria para esta marcación.', $res->json('data.results.0.message'));
    }

    public function test_limits_and_authentication(): void
    {
        $items = array_map(fn ($i) => $this->item('break_start', '2026-10-09T12:00:00-05:00'), range(1, 51));

        $this->actingIn($this->emp, $this->acme)->post('/api/v1/attendances/sync-batch', ['punches' => $items])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');

        $this->actingIn($this->emp, $this->acme)->postJson('/api/v1/attendances/sync-batch', [])
            ->assertStatus(422);

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/attendances/sync-batch', ['punches' => [$this->item('clock_in', '2026-10-09T08:00:00-05:00')]], ['X-Tenant-Slug' => 'acme'])
            ->assertStatus(401);
    }
}
