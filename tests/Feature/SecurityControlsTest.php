<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Bloque 7 (auditoría): intentos de saltarse controles que no tenían prueba propia.
 * Contraseña inicial = DNI con cambio obligatorio se conserva (CredentialPolicyTest); aquí: el DNI no se puede
 * volver a usar, el hash es Argon2id y CORS solo responde a orígenes permitidos.
 */
class SecurityControlsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_the_dni_cannot_be_set_again_as_the_password(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $ana = $this->makeUser($acme, 'employee', ['document_number' => '45879632', 'password' => '45879632', 'must_change_password' => true]);

        // Primer ingreso: cambiar la contraseña temporal (el DNI) por el mismo DNI u otra solo numérica no pasa
        foreach (['45879632', '12345678'] as $weak) {
            $this->actingIn($ana, $acme)->putJson('/api/auth/change-password', [
                'current_password' => '45879632', 'new_password' => $weak, 'new_password_confirmation' => $weak,
            ])->assertStatus(422)->assertJsonValidationErrors('new_password');
        }
        $this->assertTrue((bool) $ana->fresh()->must_change_password);

        $this->actingIn($ana, $acme)->putJson('/api/auth/change-password', [
            'current_password' => '45879632', 'new_password' => 'Segura2026x', 'new_password_confirmation' => 'Segura2026x',
        ])->assertOk();
        $this->assertFalse((bool) $ana->fresh()->must_change_password);
        $this->assertFalse(Hash::check('45879632', $ana->fresh()->password), 'El DNI deja de servir como contraseña');
    }

    public function test_passwords_are_hashed_with_argon2id(): void
    {
        if (! defined('PASSWORD_ARGON2ID')) {
            $this->markTestSkipped('PHP sin Argon2id: config/hashing.php usa bcrypt como respaldo');
        }
        $this->assertSame('argon2id', config('hashing.driver'));
        $this->assertStringStartsWith('$argon2id$', Hash::make('Segura2026x'));
    }

    public function test_cors_only_answers_allowed_origins(): void
    {
        config(['cors.allowed_origins' => ['https://group-cyp.com']]);

        // Un origen ajeno nunca recibe su propio origen ni "*" (el navegador bloquea la lectura)
        $evil = $this->withHeaders(['Origin' => 'https://evil.example'])->getJson('/api/v1/app/config');
        $this->assertNotContains($evil->headers->get('Access-Control-Allow-Origin'), ['https://evil.example', '*']);
        $this->withHeaders(['Origin' => 'https://group-cyp.com'])->getJson('/api/v1/app/config')
            ->assertHeader('Access-Control-Allow-Origin', 'https://group-cyp.com');
    }
}
