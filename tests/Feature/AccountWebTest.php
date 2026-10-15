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

    public function test_login_has_brand_panel_logo_and_self_hosted_assets(): void
    {
        $html = $this->get('/login')->assertOk()->assertSee('authThemeToggle', false)->getContent();

        // Panel de marca (escritorio) con el logo blanco y el ERP; logo arriba del formulario (celular)
        $this->assertStringContainsString('class="gn-hero"', $html);
        $this->assertStringContainsString('grupo-negreiros-logo-white.png', $html);
        $this->assertStringContainsString('ERP empresarial', $html);
        $logo = strpos($html, 'class="gn-brand"');
        $title = strpos($html, 'Bienvenido</h1>');
        $this->assertTrue($logo !== false && $logo < $title);
        // En oscuro se usa la versión blanca del logo por defecto
        $this->assertStringContainsString('has-dark', substr($html, $logo, 400));
        // Sin CDN: Bootstrap e íconos se sirven desde el propio servidor
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $html);
        $this->assertStringContainsString('vendor/bootstrap/bootstrap.min.css', $html);
    }
}
