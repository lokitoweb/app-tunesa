<?php

namespace Tests\Feature;

use App\Modules\Core\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Marca y app › Fondo del inicio de sesión: imágenes que se alternan y segundos de cada una */
class LoginBackgroundsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('public');
    }

    public function test_login_uses_the_included_backgrounds_until_some_are_uploaded(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('img/antigravity-bg.jpg', false)
            ->assertSee('img/login/oficina-5.webp', false)
            ->assertSee('data-seconds="8"', false);
    }

    public function test_platform_uploads_backgrounds_as_webp_sets_seconds_and_companies_inherit(): void
    {
        $root = $this->makeUser(null, 'super_admin');

        $this->actingAs($root)->put('/settings/branding', [
            'tab' => 'marca',
            'login_slide_seconds' => 12,
            'login_backgrounds_add' => [
                UploadedFile::fake()->image('oficina.jpg', 2400, 1350),
                UploadedFile::fake()->image('flota.png', 1600, 900),
            ],
        ])->assertSessionHasNoErrors();

        $paths = TenantSetting::rowFor(null)->login_backgrounds;
        $this->assertCount(2, $paths);
        foreach ($paths as $path) {
            $this->assertStringEndsWith('.webp', $path);
            Storage::disk('public')->assertExists($path);
        }
        $this->assertSame(1920, getimagesizefromstring(Storage::disk('public')->get($paths[0]))[0], 'se reduce a 1920 px de ancho');

        auth()->logout();
        $this->get('/login')->assertOk()->assertSee($paths[0], false)->assertSee('data-seconds="12"', false)
            ->assertDontSee('img/login/oficina-5.webp', false);

        // Una empresa sin fondos propios usa los de la plataforma
        $acme = TenantSetting::forTenant($this->makeTenant('acme'));
        $this->assertCount(2, $acme->loginBackgroundUrls());
        $this->assertSame(12, $acme->loginSlideSeconds());

        // Quitar uno borra el archivo; quitar todos vuelve a las imágenes incluidas
        $this->actingAs($root)->put('/settings/branding', ['tab' => 'marca', 'remove_login_backgrounds' => [$paths[0]]])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($paths[0]);
        $this->assertSame([$paths[1]], TenantSetting::rowFor(null)->login_backgrounds);

        $this->actingAs($root)->put('/settings/branding', ['tab' => 'marca', 'remove_login_backgrounds' => [$paths[1], 'otra/ruta.webp']])->assertSessionHasNoErrors();
        $this->assertNull(TenantSetting::rowFor(null)->login_backgrounds);
        $this->assertCount(count(TenantSetting::DEFAULT_LOGIN_BACKGROUNDS), TenantSetting::forTenant(null)->loginBackgroundUrls());
    }

    public function test_company_admin_has_its_own_backgrounds_with_limits(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');

        $this->actingAs($admin)->put('/settings/branding', [
            'tab' => 'marca',
            'login_backgrounds_add' => [UploadedFile::fake()->image('sede.jpg', 1920, 1080)],
        ])->assertSessionHasNoErrors();
        $this->assertCount(1, TenantSetting::forTenant($acme->fresh())->loginBackgroundUrls());
        $this->assertCount(count(TenantSetting::DEFAULT_LOGIN_BACKGROUNDS), TenantSetting::forTenant(null)->loginBackgroundUrls(), 'la plataforma no cambia');

        // Más de 8 en total, una imagen chica o segundos fuera de rango: no se guarda
        $many = array_map(fn ($i) => UploadedFile::fake()->image("f{$i}.jpg", 1920, 1080), range(1, 8));
        $this->actingAs($admin)->put('/settings/branding', ['tab' => 'marca', 'login_backgrounds_add' => $many])->assertSessionHasErrors('login_backgrounds_add');
        $this->actingAs($admin)->put('/settings/branding', ['tab' => 'marca', 'login_backgrounds_add' => [UploadedFile::fake()->image('chica.jpg', 300, 200)]])
            ->assertSessionHasErrors('login_backgrounds_add.0');
        $this->actingAs($admin)->put('/settings/branding', ['tab' => 'marca', 'login_slide_seconds' => 2])->assertSessionHasErrors('login_slide_seconds');
        $this->assertCount(1, TenantSetting::rowFor($acme)->login_backgrounds);
    }
}
