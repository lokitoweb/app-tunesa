<?php

namespace Tests\Feature;

use App\Modules\Core\Notifications\PasswordHelpRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * DEF-05: "Olvidé mi contraseña" en la app avisa a RRHH, que restablece la
 * clave desde la lista de Colaboradores; la temporal obliga a cambiarla.
 */
class PasswordHelpTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_forgot_password_in_the_app_notifies_hr_without_revealing_accounts(): void
    {
        $this->seedRoles();
        Notification::fake();
        $acme = $this->makeTenant('acme');
        $hr = $this->makeUser($acme, 'hr_manager');
        $ana = $this->makeUser($acme, 'employee', ['email' => 'ana@acme.test']);
        $otherHr = $this->makeUser($this->makeTenant('other'), 'hr_manager');

        $known = $this->postJson('/api/v1/auth/password-help', ['email' => 'ANA@acme.test'])->assertOk()->json('message');
        $unknown = $this->postJson('/api/v1/auth/password-help', ['email' => 'nadie@acme.test'])->assertOk()->json('message');
        $this->assertSame($known, $unknown, 'la respuesta no revela si el correo existe');

        Notification::assertSentTo($hr, PasswordHelpRequested::class, fn ($n) => $n->employee->is($ana));
        Notification::assertNotSentTo($otherHr, PasswordHelpRequested::class);

        // Un aviso por hora aunque insista
        $this->postJson('/api/v1/auth/password-help', ['email' => 'ana@acme.test'])->assertOk();
        Notification::assertSentToTimes($hr, PasswordHelpRequested::class, 1);

        $this->postJson('/api/v1/auth/password-help', ['email' => 'no-es-correo'])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_hr_resets_the_password_from_the_employee_list(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $hr = $this->makeUser($acme, 'hr_manager');
        $ana = $this->makeUser($acme, 'employee', ['first_name' => 'Ana']);

        $this->actingAs($hr)->get('/employees')->assertOk()
            ->assertSee(route('employees.reset-access', $ana->id), false)
            ->assertSee('Restablecer clave de Ana');

        $this->actingAs($hr)->post("/employees/{$ana->id}/reset-access")->assertSessionHas('credentials');
        $this->assertTrue((bool) $ana->fresh()->must_change_password, 'la temporal obliga a cambiarla al ingresar');
    }
}
