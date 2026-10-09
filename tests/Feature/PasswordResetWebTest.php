<?php

namespace Tests\Feature;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Recuperación de contraseña desde el navegador (panel y colaboradores).
 */
class PasswordResetWebTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_login_links_to_forgot_password_and_form_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('¿Olvidaste tu contraseña?')->assertSee(route('password.request'));
        $this->get('/forgot-password')->assertOk()->assertSee('Enviar enlace');
    }

    public function test_request_sends_link_to_existing_email_without_revealing_unknown_ones(): void
    {
        Notification::fake();
        $acme = $this->makeTenant('acme');
        $employee = $this->makeUser($acme, 'employee', ['email' => 'rosa@acme.pe']);

        $this->post('/forgot-password', ['email' => 'rosa@acme.pe'])->assertSessionHas('status');
        $this->post('/forgot-password', ['email' => 'nadie@acme.pe'])->assertSessionHas('status')->assertSessionHasNoErrors();

        Notification::assertSentTo($employee, ResetPassword::class, function (ResetPassword $n) use ($employee) {
            $url = $n->toMail($employee)->actionUrl;

            return str_contains($url, '/reset-password?') && str_contains($url, 'token=' . $n->token);
        });
    }

    public function test_link_from_email_opens_form_with_query_and_path_token(): void
    {
        $acme = $this->makeTenant('acme');
        $this->makeUser($acme, 'employee', ['email' => 'rosa@acme.pe']);

        $this->get('/reset-password?token=abc123&email=rosa%40acme.pe&tenant=acme')
            ->assertOk()->assertSee('value="abc123"', false)->assertSee('rosa@acme.pe')->assertSee('Acme');
        $this->get('/reset-password/abc123?email=rosa%40acme.pe')->assertOk()->assertSee('value="abc123"', false);
        $this->get('/reset-password')->assertRedirect(route('password.request'));
    }

    public function test_employee_resets_password_clears_temporary_flag_and_is_not_logged_in(): void
    {
        $acme = $this->makeTenant('acme');
        $employee = $this->makeUser($acme, 'employee', ['email' => 'rosa@acme.pe', 'must_change_password' => true]);
        $employee->createToken('app');
        $token = Password::createToken($employee);

        $this->post('/reset-password', [
            'token' => $token, 'email' => 'rosa@acme.pe',
            'password' => 'NuevaClave9', 'password_confirmation' => 'NuevaClave9',
        ])->assertRedirect(route('login'))
          ->assertSessionHas('status', 'Contraseña restablecida. Ya puedes ingresar en la app o en el panel.');

        $this->assertGuest();
        $employee->refresh();
        $this->assertTrue(Hash::check('NuevaClave9', $employee->password));
        $this->assertFalse($employee->must_change_password);
        $this->assertSame(0, $employee->tokens()->count());

        $this->get('/login')->assertSee('Ya puedes ingresar en la app o en el panel');
    }

    public function test_reset_applies_password_rules_and_rejects_invalid_token(): void
    {
        $acme = $this->makeTenant('acme');
        $user = $this->makeUser($acme, 'hr_manager', ['email' => 'rrhh@acme.pe']);
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token, 'email' => 'rrhh@acme.pe', 'password' => 'solo', 'password_confirmation' => 'solo',
        ])->assertSessionHasErrors('password');

        $this->post('/reset-password', [
            'token' => 'invalido', 'email' => 'rrhh@acme.pe', 'password' => 'NuevaClave9', 'password_confirmation' => 'NuevaClave9',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('Secret123', $user->fresh()->password));
    }
}
