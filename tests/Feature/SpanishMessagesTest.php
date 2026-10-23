<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** lang/es: las validaciones salen en español en el panel y en la API de la app */
class SpanishMessagesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_validation_messages_are_spanish_everywhere(): void
    {
        $this->seedRoles();
        $this->assertSame('es', app()->getLocale());

        // Panel: el login conserva sus mensajes propios (ya estaban en español)
        $this->post('/login', [])->assertSessionHasErrors(['email' => 'El correo corporativo es obligatorio.', 'password' => 'La contraseña es obligatoria.']);

        // API de la app: solicitud de permiso sin motivo
        $acme = $this->makeTenant('acme');
        $emp = $this->makeUser($acme);
        $this->actingIn($emp, $acme)->postJson('/api/leave-requests', ['type' => 'personal'])
            ->assertStatus(422)->assertJsonPath('errors.reason.0', 'Falta el motivo.');

        // Ningún mensaje de Laravel queda en inglés
        foreach (trans('validation') as $key => $message) {
            foreach ((array) $message as $text) {
                $this->assertDoesNotMatchRegularExpression('/\b(must|field|required|valid)\b/i', (string) $text, "validation.{$key}");
            }
        }
    }
}
