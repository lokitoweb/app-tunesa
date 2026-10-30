<?php

namespace Tests\Feature;

use App\Modules\Core\Controllers\Api\AuthController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * "¿Olvidaste tu contraseña?": la persona pide una contraseña temporal por correo
 * (PasswordRecoveryTest cubre el detalle). No hay enlaces ni tokens de restablecimiento,
 * y RRHH/Administración sigue pudiendo restablecer el acceso desde el panel.
 */
class PasswordResetWebTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_forgot_password_page_asks_for_the_email(): void
    {
        $this->get('/login')->assertOk()->assertSee('¿Olvidaste tu contraseña?')->assertSee(route('password.request'));

        $this->get('/forgot-password')->assertOk()
            ->assertSee('<form', false)->assertSee(route('password.email'), false)
            ->assertSee('Enviar contraseña temporal')
            ->assertSee('Recursos Humanos también puede restablecer tu acceso')
            ->assertSee(route('login'));
    }

    public function test_only_the_temporary_password_flow_exists_no_reset_links(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $this->makeUser($acme, 'hr_manager', ['email' => 'rrhh@acme.test']);

        \Illuminate\Support\Facades\Notification::fake();
        $this->post('/forgot-password', ['email' => 'rrhh@acme.test'])->assertRedirect(route('password.request'));
        $this->get('/reset-password/x')->assertNotFound();
        $this->post('/reset-password', ['token' => 'x', 'email' => 'rrhh@acme.test', 'password' => 'Nueva123!', 'password_confirmation' => 'Nueva123!'])
            ->assertNotFound();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        // La API de la app (410 PASSWORD_RESET_BY_ADMIN) ya la cubre LoginByEmailTest
    }

    public function test_hr_resets_access_from_the_panel_with_the_dni_as_temporary_password(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $hr = $this->makeUser($acme, 'hr_manager');
        $emp = $this->makeUser($acme, 'employee', ['document_number' => '45678912']);

        $this->actingAs($hr)->post("/employees/{$emp->id}/reset-access")->assertSessionHas('credentials.password', '45678912');

        $emp->refresh();
        $this->assertTrue(Hash::check('45678912', $emp->password));
        $this->assertTrue((bool) $emp->must_change_password, 'debe cambiarla al ingresar');
        $this->assertNotNull($emp->temp_password_expires_at);
    }
}
