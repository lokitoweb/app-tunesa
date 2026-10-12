<?php

namespace Tests\Feature;

use App\Modules\Core\Notifications\SecurityAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Mi cuenta en el panel, y el logo y el tema en las pantallas de acceso.
 */
class AccountWebTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_account_page_shows_the_user_and_is_linked_from_the_menu(): void
    {
        $acme = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin', ['first_name' => 'Rosa', 'email' => 'rosa@acme.pe']);

        $this->actingAs($admin)->get('/account')->assertOk()->assertSee('Mi cuenta')->assertSee('rosa@acme.pe')->assertSee('Cambiar contraseña');
        // El menú de la cuenta enlaza aquí y la barra superior trae el buscador de colaboradores
        $this->actingAs($admin)->get('/notifications')->assertOk()->assertSee(route('account.edit'))->assertSee('topbar-search', false);
    }

    public function test_password_change_requires_the_current_one_and_a_strong_new_one(): void
    {
        $acme = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');

        $this->actingAs($admin)->put('/account/password', [
            'current_password' => 'Wrong123', 'password' => 'Nueva2026x', 'password_confirmation' => 'Nueva2026x',
        ])->assertSessionHasErrors('current_password');

        $this->actingAs($admin)->put('/account/password', [
            'current_password' => 'Secret123', 'password' => 'debil', 'password_confirmation' => 'debil',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('Secret123', $admin->fresh()->password));
    }

    public function test_password_change_saves_it_closes_app_sessions_and_alerts(): void
    {
        Notification::fake();
        $acme = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $admin->createToken('celular');

        $this->actingAs($admin)->put('/account/password', [
            'current_password' => 'Secret123', 'password' => 'Nueva2026x', 'password_confirmation' => 'Nueva2026x',
        ])->assertRedirect(route('account.edit'))->assertSessionHas('success');

        $this->assertTrue(Hash::check('Nueva2026x', $admin->fresh()->password));
        $this->assertSame(0, $admin->tokens()->count());
        $this->assertAuthenticatedAs($admin);
        Notification::assertSentTo($admin, SecurityAlert::class, fn (SecurityAlert $n) => $n->kind === 'password_changed');
    }

    public function test_login_shows_the_logo_inside_the_card_and_a_theme_toggle(): void
    {
        $html = $this->get('/login')->assertOk()->assertSee('authThemeToggle', false)->getContent();

        // El logo va dentro de la tarjeta, antes del título "Iniciar sesión"
        $card = strpos($html, 'card-body');
        $logo = strpos($html, 'class="auth-brand"');
        $title = strpos($html, 'Iniciar sesión</h1>');
        $this->assertTrue($card < $logo && $logo < $title);
        // Sin logo oscuro subido, el claro no se oculta en el tema oscuro
        $this->assertStringNotContainsString('has-dark', substr($html, $logo, 400));
    }
}
