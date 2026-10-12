<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\CompanyConfiguration;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Shift;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\Attendance\Services\Attendance\SelfieStorage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Bitácora inmutable de marcaciones, idempotencia, banderas de sospecha
 * (desfase, GPS simulado, rostro, fuera de horario, fuera de geocerca),
 * descansos offline, selfie en WebP y avisos.
 */
class AttendancePunchLogTest extends TestCase
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
        Storage::fake('public');
        Storage::fake('private');
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['display_name' => 'Av. Javier Prado Este 2578, San Isidro, Lima'])]);

        // Jueves 9 de octubre de 2026, 08:05 en Lima
        $this->travelTo(Carbon::parse('2026-10-09 08:05:00', 'America/Lima'));

        $this->acme       = $this->makeTenant('acme');
        $this->supervisor = $this->makeUser($this->acme, 'supervisor');
        $this->emp        = $this->makeUser($this->acme, 'employee', ['employee_code' => 'EMP-001']);
        $this->branch     = $this->makeBranch($this->acme, ['allowed_radius_meters' => 100, 'supervisor_id' => $this->supervisor->id]);
        $this->emp->branches()->attach($this->branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12,
            'device_id' => 'pixel-7', 'device_os' => 'android', 'app_version' => '1.4.0', 'is_mocked' => 0,
            'photo' => UploadedFile::fake()->image('selfie.jpg', 1600, 1200),
        ], $overrides);
    }

    private function withShift(string $start = '08:00', string $end = '17:00', int $grace = 10, array $days = [0, 1, 2, 3, 4, 5, 6]): Shift
    {
        $shift = Shift::create(['tenant_id' => $this->acme->id, 'name' => 'Mañana', 'start_time' => $start, 'end_time' => $end,
            'grace_period_minutes' => $grace, 'working_days' => $days, 'break_allowed' => true, 'break_duration_minutes' => 60]);
        EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'shift_id' => $shift->id,
            'branch_id' => $this->branch->id, 'effective_from' => '2020-01-01', 'is_active' => true]);
        $this->emp->notifications()->delete(); // el aviso "Tienes un nuevo horario" no es lo que se prueba aquí

        return $shift;
    }

    private function settings(?Tenant $tenant, array $values): void
    {
        TenantSetting::editableRowFor($tenant)->fill($values)->save();
    }

    private function punches(): \Illuminate\Support\Collection
    {
        return AttendancePunch::withoutGlobalScopes()->orderBy('id')->get();
    }

    public function test_every_step_of_the_day_writes_an_immutable_punch_with_its_evidence(): void
    {
        $this->withShift();
        $deviceTime = now()->utc()->subSeconds(4)->toIso8601String();

        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->payload(['device_time' => $deviceTime]))
            ->assertCreated()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('punch.type', 'clock_in')
            ->assertJsonPath('punch.suspicious', false)
            ->assertJsonPath('data.is_suspicious', false);

        $this->travel(4)->hours();
        $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/break-start', $this->payload(['photo' => null]))->assertOk();
        $this->travel(45)->minutes();
        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/break-end', $this->payload(['photo' => UploadedFile::fake()->image('b.png', 300, 300)]))->assertOk();
        $this->travelTo(Carbon::parse('2026-10-09 17:02:00', 'America/Lima'));
        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-out', $this->payload())
            ->assertOk()->assertJsonPath('punch.type', 'clock_out');

        $punches = $this->punches();
        $this->assertSame(['clock_in', 'break_start', 'break_end', 'clock_out'], $punches->pluck('type')->all());

        $in = $punches[0];
        $attendance = Attendance::withoutGlobalScopes()->sole();
        $this->assertSame($attendance->id, $in->attendance_id);
        $this->assertSame($this->branch->id, $in->branch_id);
        $this->assertSame(12.0, $in->accuracy_meters);
        $this->assertTrue($in->within_geofence);
        $this->assertSame(0, $in->distance_meters);
        $this->assertSame('2026-10-09 13:05:00', $in->server_time->format('Y-m-d H:i:s'), 'server_time en UTC');
        $this->assertSame('UTC', $in->server_time->getTimezone()->getName());
        $this->assertSame(-4, $in->time_drift_seconds);
        $this->assertSame('2026-10-09 13:04:56', $in->device_time->format('Y-m-d H:i:s'));
        $this->assertFalse($in->offline);
        $this->assertSame('pixel-7', $in->device_id);
        $this->assertSame('android', $in->device_os);
        $this->assertSame('1.4.0', $in->app_version);
        $this->assertNotNull($in->ip);
        $this->assertSame([], $in->suspicion_reasons);
        $this->assertSame('Av. Javier Prado Este 2578, San Isidro, Lima', $in->address, 'dirección completada por el job');
        Storage::disk('private')->assertExists($in->photo_path);

        // Resumen en la jornada
        $this->assertSame(12.0, $attendance->clock_in_accuracy_meters);
        $this->assertSame(-4, $attendance->clock_in_time_drift_seconds);
        $this->assertSame('2026-10-09 13:04:56', $attendance->clock_in_device_time->format('Y-m-d H:i:s'));
        $this->assertFalse($attendance->clock_in_mock_location);
        $this->assertNull($attendance->clock_in_face_detected);
        $this->assertFalse($attendance->is_suspicious);
        $this->assertSame(45, (int) $attendance->break_minutes);
        $this->assertNotNull($punches[2]->photo_path, 'el descanso acepta foto');
        $this->get($punches[2]->photoUrl())->assertOk();
        $this->get(str_replace('punch-photo', 'selfie-in', $punches[2]->photoUrl()))->assertForbidden();
        $this->assertNull($punches[1]->photo_path);
    }

    public function test_punches_refuse_updates_and_deletes_except_the_address_fill(): void
    {
        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->payload())->assertCreated();
        $punch = AttendancePunch::withoutGlobalScopes()->sole();

        try {
            $punch->latitude = 0;
            $punch->save();
            $this->fail('Se pudo editar una marcación');
        } catch (LogicException $e) {
            $this->assertStringContainsString('inmutables', $e->getMessage());
        }

        $this->expectExceptionOnDelete($punch);

        // Ni siquiera con el query builder (trigger de la base de datos)
        try {
            DB::table('attendance_punches')->where('id', $punch->id)->update(['type' => 'clock_out']);
            $this->fail('El trigger no protegió la marcación');
        } catch (QueryException $e) {
            $this->assertStringContainsString('inmutable', $e->getMessage());
        }

        // La dirección sí se completa, y solo una vez
        DB::table('attendance_punches')->where('id', $punch->id)->update(['address' => null]);
        $this->assertTrue(AttendancePunch::fillAddress($punch->id, 'Calle 1'));
        $this->assertFalse(AttendancePunch::fillAddress($punch->id, 'Calle 2'));
        $this->assertSame('Calle 1', $punch->fresh()->address);
        $this->assertSame('clock_in', $punch->fresh()->type);
    }

    private function expectExceptionOnDelete(AttendancePunch $punch): void
    {
        try {
            $punch->delete();
            $this->fail('Se pudo borrar una marcación');
        } catch (LogicException $e) {
            $this->assertStringContainsString('inmutables', $e->getMessage());
        }
        $this->assertSame(1, AttendancePunch::withoutGlobalScopes()->count());
    }

    public function test_same_client_uuid_returns_the_existing_result_and_never_creates_twice(): void
    {
        $uuid = (string) Str::uuid();

        $first = $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->payload(['client_uuid' => $uuid]))
            ->assertCreated()->assertJsonPath('duplicate', false);

        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->payload(['client_uuid' => strtoupper($uuid)]))
            ->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('punch.id', $first->json('punch.id'));

        $this->assertSame(1, AttendancePunch::withoutGlobalScopes()->count());
        $this->assertSame(1, Attendance::withoutGlobalScopes()->count());

        // Breaks también son idempotentes
        $breakUuid = (string) Str::uuid();
        $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/break-start', $this->payload(['photo' => null, 'client_uuid' => $breakUuid]))->assertOk()->assertJsonPath('duplicate', false);
        $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/break-start', $this->payload(['photo' => null, 'client_uuid' => $breakUuid]))->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(2, AttendancePunch::withoutGlobalScopes()->count());

        // El mismo uuid de otra persona de la empresa es un conflicto
        $other = $this->makeUser($this->acme);
        $other->branches()->attach($this->branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $this->actingIn($other, $this->acme)->post('/api/attendance/clock-in', $this->payload(['client_uuid' => $uuid]))
            ->assertStatus(409)->assertJsonPath('code', 'CLIENT_UUID_CONFLICT');

        // En otra empresa el mismo uuid es independiente
        app(\App\Modules\Core\Services\TenantContext::class)->clear();
        $globex = $this->makeTenant('globex');
        $gEmp   = $this->makeUser($globex);
        $gEmp->branches()->attach($this->makeBranch($globex)->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $this->actingIn($gEmp, $globex)->post('/api/attendance/clock-in', $this->payload(['client_uuid' => $uuid]))
            ->assertCreated()->assertJsonPath('duplicate', false);
    }

    public function test_mock_location_policy_rejects_or_flags(): void
    {
        // Por defecto: reject
        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->payload(['is_mocked' => 1]))
            ->assertStatus(422)->assertJsonPath('code', 'MOCK_LOCATION_DETECTED');
        $this->assertSame(0, AttendancePunch::withoutGlobalScopes()->count());
        Storage::disk('private')->assertDirectoryEmpty('/');

        $this->settings($this->acme, ['mock_location_policy' => 'flag']);

        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->payload(['is_mocked' => 1]))
            ->assertCreated()
            ->assertJsonPath('punch.suspicious', true)
            ->assertJsonPath('punch.suspicion_reasons', ['MOCK_LOCATION'])
            ->assertJsonPath('punch.mock_location_detected', true)
            ->assertJsonPath('data.is_suspicious', true);

        $attendance = Attendance::withoutGlobalScopes()->sole();
        $this->assertTrue($attendance->clock_in_mock_location);
        $this->assertTrue($attendance->is_suspicious);

        // Los descansos también aplican la política (y marcan la jornada)
        $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/break-start', $this->payload(['photo' => null, 'is_mocked' => 1]))
            ->assertOk()->assertJsonPath('punch.suspicion_reasons', ['MOCK_LOCATION']);
    }

    public function test_unsafe_device_is_accepted_but_flagged_for_hr(): void
    {
        $this->actingIn($this->emp, $this->acme)->withHeader('X-Device-Integrity', 'rooted, hooked, inventado')
            ->post('/api/attendance/clock-in', $this->payload())
            ->assertCreated()
            ->assertJsonPath('punch.suspicious', true)
            ->assertJsonPath('punch.suspicion_reasons', ['UNSAFE_DEVICE']);

        $attendance = Attendance::withoutGlobalScopes()->sole();
        $this->assertSame(['rooted', 'hooked'], $attendance->clock_in_meta['device_flags']);
        $this->assertSame(['Root / jailbreak', 'Herramienta de manipulación (Frida/Xposed)'],
            app(\App\Modules\Attendance\Services\AttendanceEvidence::class)->detail($attendance)['clock_in']['device_flags']);
    }

    public function test_time_drift_beyond_the_limit_is_flagged_but_never_rejected(): void
    {
        $this->settings(null, ['max_time_drift_seconds' => 120]);

        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->payload([
            'device_time' => now()->utc()->addMinutes(10)->toIso8601String(),
        ]))->assertCreated()
            ->assertJsonPath('punch.time_drift_seconds', 600)
            ->assertJsonPath('punch.suspicion_reasons', ['TIME_DRIFT']);

        // Dentro del límite (con otra zona horaria en el ISO 8601): sin bandera
        $this->travel(1)->hours();
        $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/break-start', $this->payload([
            'photo' => null, 'device_time' => now()->setTimezone('Asia/Tokyo')->subSeconds(90)->toIso8601String(),
        ]))->assertOk()->assertJsonPath('punch.time_drift_seconds', -90)->assertJsonPath('punch.suspicious', false);
    }

    public function test_face_policy_unverified_flags_and_explicit_false_rejects(): void
    {
        $this->settings($this->acme, ['face_detection_required' => true]);

        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->payload(['face_detected' => 0]))
            ->assertStatus(422)->assertJsonPath('code', 'FACE_NOT_DETECTED');

        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->payload())
            ->assertCreated()->assertJsonPath('punch.suspicion_reasons', ['FACE_UNVERIFIED']);
        $this->assertNull(Attendance::withoutGlobalScopes()->sole()->clock_in_face_detected);

        $this->travelTo(Carbon::parse('2026-10-09 17:00:00', 'America/Lima'));
        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-out', $this->payload(['face_detected' => 1]))
            ->assertOk()->assertJsonPath('punch.suspicious', false)->assertJsonPath('punch.face_detected', true);
        $this->assertTrue(Attendance::withoutGlobalScopes()->sole()->clock_out_face_detected);
        $this->assertTrue(Attendance::withoutGlobalScopes()->sole()->is_suspicious, 'la jornada sigue sospechosa por la entrada');
    }

    public function test_outside_schedule_is_accepted_flagged_and_notified(): void
    {
        $this->withShift('08:00', '17:00', 10);
        $this->travelTo(Carbon::parse('2026-10-09 07:30:00', 'America/Lima')); // 30 min antes, tolerancia 10

        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->payload())
            ->assertCreated()->assertJsonPath('punch.suspicion_reasons', ['OUTSIDE_SCHEDULE']);

        $empNotes = $this->emp->notifications()->get();
        $this->assertSame(['attendance.outside_schedule'], $empNotes->pluck('type')->all());
        $this->assertStringContainsString('07:30', $empNotes[0]->data['body']);
        $this->assertSame(['attendance.outside_schedule'], $this->supervisor->notifications()->pluck('type')->all());

        // Salida dentro de la ventana (17:00 + 10): sin bandera; 17:30 sí
        $this->travelTo(Carbon::parse('2026-10-09 17:30:00', 'America/Lima'));
        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-out', $this->payload())
            ->assertOk()->assertJsonPath('punch.suspicion_reasons', ['OUTSIDE_SCHEDULE']);
    }

    public function test_inside_the_window_and_rest_days(): void
    {
        $this->withShift('08:00', '17:00', 10, [1, 2, 3, 4, 5]); // jueves sí trabaja

        $this->travelTo(Carbon::parse('2026-10-09 07:52:00', 'America/Lima'));
        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->payload())
            ->assertCreated()->assertJsonPath('punch.suspicious', false);
        $this->assertSame(0, $this->emp->notifications()->count());

        // Sábado (día de descanso): fuera de horario
        $this->travelTo(Carbon::parse('2026-10-10 09:00:00', 'America/Lima'));
        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->payload())
            ->assertCreated()->assertJsonPath('punch.suspicion_reasons', ['OUTSIDE_SCHEDULE']);
    }

    public function test_night_shift_crossing_midnight(): void
    {
        $this->withShift('22:00', '06:00', 15);
        $this->travelTo(Carbon::parse('2026-10-09 21:50:00', 'America/Lima'));
        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->payload())
            ->assertCreated()->assertJsonPath('punch.suspicious', false);
    }

    public function test_out_of_geofence_flag_policy_flags_and_reject_policy_notifies(): void
    {
        $far = ['latitude' => -12.0960, 'longitude' => -77.03560]; // ≈ 200 m al sur

        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->payload($far))
            ->assertStatus(422)->assertJsonPath('code', 'GEOFENCE_VIOLATION');
        // Reintento inmediato: no se repite el aviso
        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->payload($far))->assertStatus(422);

        $notes = $this->emp->notifications()->get();
        $this->assertCount(1, $notes);
        $this->assertSame('attendance.outside_geofence', $notes[0]->type);
        $this->assertTrue($notes[0]->data['data']['rejected']);
        $this->assertSame(1, $this->supervisor->notifications()->count());
        $this->assertSame(0, AttendancePunch::withoutGlobalScopes()->count(), 'un rechazo no deja marcación');

        $config = CompanyConfiguration::forTenantId($this->acme->id);
        $config->geofence_policy = 'flag';
        $config->save();

        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->payload($far))
            ->assertCreated()
            ->assertJsonPath('punch.within_geofence', false)
            ->assertJsonPath('punch.suspicion_reasons', ['OUT_OF_GEOFENCE']);

        $this->assertSame(2, $this->emp->notifications()->count());
        $this->assertEqualsCanonicalizing([true, false], $this->emp->notifications()->get()->map(fn ($n) => $n->data['data']['rejected'])->all());
    }

    public function test_breaks_honor_offline_recorded_at_and_keep_order(): void
    {
        $this->settings($this->acme, ['offline_mode_enabled' => true, 'offline_max_hours' => 24]);
        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->payload())->assertCreated();

        $this->travelTo(Carbon::parse('2026-10-09 14:00:00', 'America/Lima'));
        $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/break-start', $this->payload([
            'photo' => null, 'offline_recorded_at' => '2026-10-09T12:00:00-05:00',
        ]))->assertOk()->assertJsonPath('data.break.start_time', '12:00:00');

        // Fin de descanso anterior al inicio: fuera de orden
        $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/break-end', $this->payload([
            'photo' => null, 'offline_recorded_at' => '2026-10-09T11:59:00-05:00',
        ]))->assertStatus(422)->assertJsonPath('code', 'OFFLINE_OUT_OF_ORDER');

        $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/break-end', $this->payload([
            'photo' => null, 'offline_recorded_at' => '2026-10-09T12:40:00-05:00',
        ]))->assertOk()->assertJsonPath('data.break.duration_min', 40);

        $punch = AttendancePunch::withoutGlobalScopes()->where('type', 'break_end')->sole();
        $this->assertTrue($punch->offline);
        $this->assertSame('2026-10-09 17:40:00', $punch->offline_recorded_at->format('Y-m-d H:i:s'));
        $this->assertNull($punch->time_drift_seconds, 'offline sin device_sent_at: no se mide el desfase');

        // Offline no permitido: también en descansos
        $this->settings($this->acme, ['offline_mode_enabled' => false]);
        $this->actingIn($this->emp, $this->acme)->postJson('/api/attendance/break-start', $this->payload([
            'photo' => null, 'offline_recorded_at' => '2026-10-09T13:00:00-05:00',
        ]))->assertStatus(422)->assertJsonPath('code', 'OFFLINE_NOT_ALLOWED');
    }

    public function test_selfie_is_converted_to_webp_and_resized(): void
    {
        if (! SelfieStorage::webpSupported()) {
            $this->markTestSkipped('GD sin soporte WebP en este entorno.');
        }

        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', $this->payload([
            'photo' => UploadedFile::fake()->image('selfie.jpg', 2400, 1800),
        ]))->assertCreated();

        $path = Attendance::withoutGlobalScopes()->sole()->clock_in_photo;
        $this->assertStringEndsWith('.webp', $path);
        $this->assertSame($path, AttendancePunch::withoutGlobalScopes()->sole()->photo_path);

        $bytes = Storage::disk('private')->get($path);
        $this->assertSame('RIFF', substr($bytes, 0, 4));
        $this->assertSame('WEBP', substr($bytes, 8, 4));
        [$w, $h] = getimagesizefromstring($bytes);
        $this->assertSame([1280, 960], [$w, $h]);

        // Un PNG chico se convierte sin agrandarse
        $small = UploadedFile::fake()->image('a.png', 200, 100);
        $png   = (new SelfieStorage())->toWebp($small->getRealPath(), 'image/png');
        $this->assertSame([200, 100], array_slice(getimagesizefromstring($png), 0, 2));
    }

    public function test_storage_keeps_the_original_format_when_gd_has_no_webp(): void
    {
        // Sin conversión posible: se guarda el original con su extensión
        $webp = UploadedFile::fake()->image('ya.webp', 100, 100);
        $path = (new SelfieStorage())->store($webp, $this->acme->id, $this->emp->id, 'clock_in');
        $this->assertStringEndsWith('.webp', $path);
        Storage::disk('private')->assertExists($path);

        if (SelfieStorage::webpSupported()) {
            $this->assertNull((new SelfieStorage())->toWebp(__FILE__, 'image/jpeg'), 'un archivo que no es imagen no se convierte');
        }
    }

    public function test_app_config_exposes_the_new_policies(): void
    {
        $this->settings(null, ['mock_location_policy' => 'flag', 'max_time_drift_seconds' => 90, 'geocoding_provider' => 'google']);

        $this->getJson('/api/v1/app/config?tenant_slug=acme')->assertOk()
            ->assertJsonPath('data.mobile.mock_location_policy', 'flag')
            ->assertJsonPath('data.mobile.max_time_drift_seconds', 90)
            ->assertJsonPath('data.mobile.geocoding_provider', 'nominatim') // google sin clave → nominatim
            ->assertJsonPath('data.mobile.fcm_enabled', false);

        $this->settings($this->acme, ['mock_location_policy' => 'reject', 'maps_api_key' => 'AIzaKey']);
        $this->getJson('/api/v1/app/config?tenant_slug=acme')->assertOk()
            ->assertJsonPath('data.mobile.mock_location_policy', 'reject')
            ->assertJsonPath('data.mobile.geocoding_provider', 'google')
            ->assertDontSee('AIzaKey');
    }

    public function test_mock_policy_falls_back_to_the_env_flag(): void
    {
        config(['attendance.reject_mock_location' => false]);
        $this->assertSame('flag', TenantSetting::forTenant($this->acme)->mockLocationPolicy());
        config(['attendance.reject_mock_location' => true]);
        $this->assertSame('reject', TenantSetting::forTenant($this->acme)->mockLocationPolicy());
    }
}
