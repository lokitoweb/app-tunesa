<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Texto con UTF-8 inválido: 400 limpio, nada guardado y el JSON sigue funcionando */
class InvalidUtf8InputTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_invalid_utf8_is_rejected_before_reaching_attendance(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $emp = $this->makeUser($acme);
        $branch = $this->makeBranch($acme);
        $attendance = Attendance::withoutGlobalScopes()->create([
            'tenant_id' => $acme->id, 'user_id' => $emp->id, 'branch_id' => $branch->id, 'work_date' => now()->toDateString(),
            'status' => 'absent', 'clock_in_at' => now()->subHours(8), 'clock_out_at' => now(),
        ]);

        // "médica" en Latin-1 (byte E9 suelto): lo que mandó el curl de Windows en la auditoría
        $latin1 = "Cita m\xE9dica";

        $this->actingIn($emp, $acme)->post("/api/attendance/{$attendance->id}/justification", ['notes' => $latin1])
            ->assertStatus(400)
            ->assertJson(['success' => false, 'code' => 'INVALID_ENCODING', 'field' => 'notes']);
        $this->assertNull($attendance->fresh()->notes, 'no se guardó el texto inválido');

        // Anidado y en la query string también
        $this->actingIn($emp, $acme)->post('/api/attendances/sync-batch', ['items' => [['notes' => $latin1]]])
            ->assertStatus(400)->assertJsonPath('field', 'items.0.notes');
        $this->actingIn($emp, $acme)->getJson('/api/attendance/history?q=' . rawurlencode($latin1))->assertStatus(400);

        // El JSON del colaborador sigue sano y el UTF-8 válido (tildes, ñ, emoji) pasa
        $this->actingIn($emp, $acme)->getJson('/api/attendance/today')->assertOk();
        $this->actingIn($emp, $acme)->post("/api/attendance/{$attendance->id}/justification", ['notes' => 'Cita médica en Ñaña 🩺'])
            ->assertOk();
        $this->assertSame('Cita médica en Ñaña 🩺', $attendance->fresh()->notes);
    }

    public function test_panel_gets_a_plain_400_and_login_still_works(): void
    {
        $this->seedRoles();
        $this->post('/login', ['email' => "x\xFF@acme.test", 'password' => 'secret'])
            ->assertStatus(400)->assertSee('caracteres no válidos');
        $this->get('/login')->assertOk();
    }
}
