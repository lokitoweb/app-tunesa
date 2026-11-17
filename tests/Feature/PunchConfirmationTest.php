<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Confirmar una marcación enviada sin respuesta (GET /api/attendance/punches/{client_uuid}):
 * la app pregunta si llegó, sin volver a subir la foto.
 */
class PunchConfirmationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_the_app_confirms_a_punch_by_its_client_uuid_without_resending_it(): void
    {
        $this->seedRoles();
        Storage::fake('public');
        Storage::fake('private');
        $acme = $this->makeTenant('acme');
        $emp  = $this->makeUser($acme);
        $emp->branches()->attach($this->makeBranch($acme)->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $other = $this->makeUser($acme);
        $uuid = (string) Str::uuid();

        // Aún no llegó
        $this->actingIn($emp, $acme)->getJson("/api/attendance/punches/{$uuid}")
            ->assertNotFound()->assertJsonPath('code', 'PUNCH_NOT_FOUND');

        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', [
            'latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12, 'device_id' => 'd', 'device_os' => 'android',
            'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640), 'client_uuid' => $uuid,
        ])->assertCreated();

        // Llegó: misma respuesta que un reenvío (duplicate), sin crear nada
        $this->actingIn($emp, $acme)->getJson('/api/attendance/punches/' . strtoupper($uuid))
            ->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('punch.type', 'clock_in')
            ->assertJsonPath('data.id', \App\Modules\Attendance\Models\Attendance::where('user_id', $emp->id)->value('id'));
        $this->assertSame(1, \App\Modules\Attendance\Models\AttendancePunch::count());

        // El identificador de otra persona no se revela
        $this->actingIn($other, $acme)->getJson("/api/attendance/punches/{$uuid}")
            ->assertStatus(409)->assertJsonPath('code', 'CLIENT_UUID_CONFLICT');
    }
}
