<?php

namespace Tests\Feature;

use App\Modules\Core\Controllers\Api\AuthController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Política de la empresa: sin recuperación de contraseña por autoservicio.
 * El login muestra un aviso y solo RRHH/Administración restablece el acceso.
 */
class PasswordResetWebTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_forgot_password_is_an_institutional_notice_without_a_form(): void
    {
        $this->get('/login')->assertOk()->assertSee('¿Olvidaste tu contraseña?')->assertSee(route('password.request'));

        $this->get('/forgot-password')->assertOk()
            ->assertSee(AuthController::RESET_BY_ADMIN_MESSAGE)
            ->assertDontSee('<form', false)
            ->assertSee(route('login'));
    }

    public function test_self_service_endpoints_no_longer_exist(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $this->makeUser($acme, 'hr_manager', ['email' => 'rrhh@acme.test']);

        $this->post('/forgot-password', ['email' => 'rrhh@acme.test'])->assertStatus(405);
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
