<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Services\Attendance\SelfieStorage;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SecureFileUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Fase 2 · selfie segura: se recodifica sin EXIF, huella SHA-256 inmutable,
 * miniatura para listados, STALE_PHOTO, auditoría de quién la ve y borrado
 * por retención (attendance:purge-photos).
 */
class SelfieEvidenceTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;
    private User $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('public');
        Storage::fake('private');

        $this->acme = $this->makeTenant('acme');
        $this->emp  = $this->makeUser($this->acme);
        $branch     = $this->makeBranch($this->acme, ['allowed_radius_meters' => 100]);
        $this->emp->branches()->attach($branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $this->travelTo(Carbon::parse('2026-10-09 08:00:00', 'America/Lima'));
    }

    /** JPEG con un bloque EXIF (APP1) como los que manda un teléfono */
    private function jpegWithExif(): UploadedFile
    {
        $image = imagecreatetruecolor(640, 480);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 40));
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();

        $exif = "Exif\0\0" . 'GPS -12.09418 -77.03560 Pixel 7 SECRETO';
        $app1 = "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif;
        $path = tempnam(sys_get_temp_dir(), 'selfie');
        file_put_contents($path, substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2));

        return new UploadedFile($path, 'selfie.jpg', 'image/jpeg', null, true);
    }

    private function clockIn(array $overrides = [])
    {
        return $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', array_merge([
            'latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12,
            'device_id' => 'test-device', 'device_os' => 'android', 'is_mocked' => 0,
            'photo' => $this->jpegWithExif(),
        ], $overrides));
    }

    public function test_selfie_is_reencoded_without_metadata_and_fingerprinted(): void
    {
        $this->assertStringContainsString('SECRETO', file_get_contents($this->jpegWithExif()->getRealPath()));

        $this->clockIn()->assertCreated();

        $punch = AttendancePunch::withoutGlobalScopes()->sole();
        $bytes = Storage::disk('private')->get($punch->photo_path);
        $this->assertStringNotContainsString('SECRETO', $bytes);
        $this->assertStringNotContainsString('Exif', $bytes);
        $this->assertSame(hash('sha256', $bytes), $punch->photo_sha256);
        $this->assertSame($punch->photo_sha256, $punch->toApi()['photo_sha256']);

        [$w, $h] = getimagesizefromstring(Storage::disk('private')->get(SelfieStorage::thumbPathFor($punch->photo_path)));
        $this->assertSame([160, 120], [$w, $h]);
    }

    public function test_fingerprint_cannot_be_changed_but_purge_mark_can(): void
    {
        $this->clockIn()->assertCreated();
        $id = AttendancePunch::withoutGlobalScopes()->sole()->id;

        $this->assertSame(1, AttendancePunch::markPhotoPurged([$id]));
        $this->assertNotNull(AttendancePunch::withoutGlobalScopes()->find($id)->photo_purged_at);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('attendance_punches')->where('id', $id)->update(['photo_sha256' => str_repeat('0', 64)]);
    }

    public function test_photo_taken_long_before_sending_is_flagged(): void
    {
        $now = now()->utc();

        // 3 min entre la selfie y el envío (reloj del teléfono adelantado 1 h: no importa)
        $this->clockIn([
            'device_time'    => $now->copy()->addHour()->subMinutes(3)->toIso8601String(),
            'device_sent_at' => $now->copy()->addHour()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('punch.suspicion_reasons', ['TIME_DRIFT', 'STALE_PHOTO']);
    }

    public function test_stale_photo_is_independent_of_clock_drift(): void
    {
        $now = now()->utc();

        // 3 min de antigüedad con el reloj en hora: no es desfase, sí foto vieja
        $this->clockIn([
            'device_time'    => $now->copy()->subMinutes(3)->toIso8601String(),
            'device_sent_at' => $now->toIso8601String(),
        ])->assertCreated()->assertJsonPath('punch.suspicion_reasons', ['STALE_PHOTO']);

        // Salida: 1 min entre foto y envío → sin bandera
        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-out', [
            'latitude' => -12.09420, 'longitude' => -77.03560, 'accuracy_meters' => 12, 'is_mocked' => 0,
            'photo' => $this->jpegWithExif(),
            'device_time' => $now->copy()->subMinute()->toIso8601String(), 'device_sent_at' => $now->toIso8601String(),
        ])->assertOk()->assertJsonPath('punch.suspicious', false);
    }

    public function test_apps_without_sent_time_are_not_flagged(): void
    {
        $this->clockIn(['device_time' => now()->utc()->subMinutes(3)->toIso8601String()])
            ->assertCreated()->assertJsonPath('punch.suspicious', false);
    }

    public function test_thumbnail_route_serves_the_small_file_or_falls_back_to_the_photo(): void
    {
        $this->clockIn()->assertCreated();
        $attendance = Attendance::withoutGlobalScopes()->sole();

        $thumb = app(SecureFileUrl::class)->thumb('selfie-in', $attendance->id);
        [$w] = getimagesizefromstring($this->get($thumb)->assertOk()->streamedContent());
        $this->assertSame(160, $w);

        // Foto anterior a la Fase 2 (sin miniatura): se sirve la completa
        Storage::disk('private')->delete(SelfieStorage::thumbPathFor($attendance->clock_in_photo));
        [$w] = getimagesizefromstring($this->get($thumb)->assertOk()->streamedContent());
        $this->assertSame(640, $w);

        $this->get(str_replace('selfie-in-thumb', 'document-thumb', $thumb))->assertNotFound();
    }

    public function test_opening_someone_elses_selfie_is_audited_once_per_hour(): void
    {
        $this->clockIn()->assertCreated();
        $attendance = Attendance::withoutGlobalScopes()->sole();
        $hr = $this->makeUser($this->acme, 'hr_manager');
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $this->actingAs($hr)->getJson(route('attendance.evidence', $attendance->id))->assertOk();
        $this->actingAs($hr)->get(route('attendance.show', $attendance->id))->assertOk()->assertSee(substr(AttendancePunch::withoutGlobalScopes()->sole()->photo_sha256, 0, 12));

        $logs = AuditLog::withoutGlobalScopes()->where('action', 'attendance.photo_viewed')->get();
        $this->assertCount(1, $logs);
        $this->assertSame($hr->id, $logs[0]->user_id);
        $this->assertSame($attendance->id, $logs[0]->subject_id);

        $this->travel(61)->minutes();
        $this->actingAs($hr)->getJson(route('attendance.evidence', $attendance->id))->assertOk();
        $this->assertSame(2, AuditLog::withoutGlobalScopes()->where('action', 'attendance.photo_viewed')->count());
    }

    public function test_purge_deletes_old_selfies_and_keeps_the_evidence_trail(): void
    {
        $this->clockIn()->assertCreated();
        $old = AttendancePunch::withoutGlobalScopes()->sole();
        $oldAttendance = Attendance::withoutGlobalScopes()->sole();

        $this->travelTo(Carbon::parse('2027-11-10 08:00:00', 'America/Lima'));
        $this->clockIn()->assertCreated();
        $recent = AttendancePunch::withoutGlobalScopes()->latest('id')->first();

        $this->artisan('attendance:purge-photos', ['--dry-run' => true])->expectsOutputToContain('Se borrarían 1')->assertSuccessful();
        Storage::disk('private')->assertExists($old->photo_path);

        $this->artisan('attendance:purge-photos')->expectsOutputToContain('Se borraron 1')->assertSuccessful();

        Storage::disk('private')->assertMissing([$old->photo_path, SelfieStorage::thumbPathFor($old->photo_path)]);
        Storage::disk('private')->assertExists($recent->photo_path);

        $old = $old->fresh();
        $this->assertNotNull($old->photo_purged_at);
        $this->assertNotNull($old->photo_path, 'la bitácora conserva la ruta y la huella');
        $this->assertNotNull($old->photo_sha256);
        $this->assertNull($old->photoUrl());
        $this->assertNull($oldAttendance->fresh()->clock_in_photo);
        $this->assertNotNull(Attendance::withoutGlobalScopes()->latest('id')->first()->clock_in_photo);

        $log = AuditLog::withoutGlobalScopes()->where('action', 'attendance.photos_purged')->sole();
        $this->assertSame($this->acme->id, $log->tenant_id);

        // Repetirlo no vuelve a contar las ya borradas
        $this->artisan('attendance:purge-photos')->expectsOutputToContain('Se borraron 0')->assertSuccessful();
    }

    public function test_altered_or_missing_selfie_is_flagged_and_audited(): void
    {
        $this->clockIn()->assertCreated();
        $attendance = Attendance::withoutGlobalScopes()->sole();
        $punch = AttendancePunch::withoutGlobalScopes()->sole();
        $hr = $this->makeUser($this->acme, 'hr_manager');
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        // Intacta: sin aviso
        $this->actingAs($hr)->getJson(route('attendance.evidence', $attendance->id))->assertOk()
            ->assertJsonPath('clock_in.photo_integrity', 'ok')->assertJsonPath('evidence_altered', false);

        // Alguien reemplaza el archivo en el disco
        Storage::disk('private')->put($punch->photo_path, 'otra imagen');
        $this->actingAs($hr)->getJson(route('attendance.evidence', $attendance->id))->assertOk()
            ->assertJsonPath('clock_in.photo_integrity', 'altered')
            ->assertJsonPath('punches.0.photo_integrity', 'altered')
            ->assertJsonPath('evidence_altered', true);
        $this->actingAs($hr)->get(route('attendance.show', $attendance->id))->assertOk()
            ->assertSee('Evidencia alterada.')->assertSee('Alterada');

        // Se audita una vez (no en cada vista)
        $logs = AuditLog::withoutGlobalScopes()->where('action', 'attendance.evidence_altered')->get();
        $this->assertCount(1, $logs);
        $this->assertSame('altered', $logs[0]->meta['context']['problem']);

        // Si falta el archivo también se avisa
        Storage::disk('private')->delete($punch->photo_path);
        $this->actingAs($hr)->getJson(route('attendance.evidence', $attendance->id))
            ->assertJsonPath('clock_in.photo_integrity', 'missing')->assertJsonPath('evidence_altered', true);

        // Borrada por retención: no es una alteración
        AttendancePunch::markPhotoPurged([$punch->id]);
        $this->actingAs($hr)->getJson(route('attendance.evidence', $attendance->id))
            ->assertJsonPath('evidence_altered', false);
    }
}
