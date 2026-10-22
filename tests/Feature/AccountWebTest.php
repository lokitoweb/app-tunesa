<?php

namespace Tests\Feature;

use App\Modules\Core\Notifications\SecurityAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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

        $this->actingAs($admin)->get('/account')->assertOk()->assertSee('Mi cuenta')->assertSee('rosa@acme.pe')->assertSee('Cambio de Contraseña');
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
        $html = $this->get('/login')->assertOk()->getContent();

        // Logo de la empresa arriba del formulario y el ERP en el subtítulo
        $this->assertStringContainsString('ERP empresarial', $html);
        $logo = strpos($html, 'class="card-brand-logo"');
        $title = strpos($html, 'Iniciar sesión</h2>');
        $this->assertTrue($logo !== false && $title !== false && $logo < $title);
        // Sin CDN: Bootstrap e íconos se sirven desde el propio servidor
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $html);
        $this->assertStringContainsString('vendor/bootstrap/bootstrap.min.css', $html);
    }

    public function test_profile_photo_replaces_the_old_one_and_super_admin_can_edit_in_global_view(): void
    {
        Storage::fake('public');
        $acme  = $this->makeTenant('acme');
        $this->makeTenant('other');
        $admin = $this->makeUser($acme, 'company_admin');

        $this->actingAs($admin)->put('/account/profile', ['phone' => '999111222', 'avatar' => UploadedFile::fake()->image('a.jpg')])
            ->assertRedirect(route('account.edit'));
        $old = $admin->fresh()->avatar_path;
        Storage::disk('public')->assertExists($old);

        $this->put('/account/profile', ['avatar' => UploadedFile::fake()->image('b.png')])->assertRedirect(route('account.edit'));
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($admin->fresh()->avatar_path);
        $this->assertSame('999111222', $admin->fresh()->phone);

        $admin->createToken('app');
        $this->post('/account/revoke-tokens')->assertRedirect(route('account.edit'));
        $this->assertSame(0, $admin->tokens()->count());

        // Mi cuenta no es un registro de empresa: el Super Admin la edita sin elegir empresa
        $root = $this->makeUser(null, 'super_admin');
        $this->actingAs($root)->put('/account/profile', ['phone' => '988777666'])->assertRedirect(route('account.edit'));
        $this->assertSame('988777666', $root->fresh()->phone);
    }
}
