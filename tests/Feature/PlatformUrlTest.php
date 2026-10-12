<?php

namespace Tests\Feature;

use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Support\PlatformUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Al cambiar de túnel, nada guardado con el dominio anterior debe seguir
 * usándose: ni la dirección que se anuncia a la app ni el receptor de webhooks.
 */
class PlatformUrlTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const OLD = 'https://rio-seem-generally-organized.trycloudflare.com';
    private const NEW = 'https://decimal-tongue-exercise-carl.trycloudflare.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('public');
        Storage::fake('private');
        config(['app.url' => self::NEW]);
    }

    public function test_helpers_detect_stale_tunnels_and_rebuild_own_receiver(): void
    {
        $this->assertTrue(PlatformUrl::isStaleTunnel(self::OLD));
        $this->assertFalse(PlatformUrl::isStaleTunnel(self::NEW . '/'));
        $this->assertFalse(PlatformUrl::isStaleTunnel('https://api.tunesa.app'));

        $this->assertSame(self::NEW . '/api/v1/webhooks/receiver/acme?canal=notificaciones',
            PlatformUrl::webhookTarget(self::OLD . '/api/v1/webhooks/receiver/acme?canal=notificaciones'));
        $this->assertSame('https://erp.example.test/hooks', PlatformUrl::webhookTarget('https://erp.example.test/hooks'));

        $this->assertSame(self::NEW, PlatformUrl::apiBaseUrl(self::OLD));
        $this->assertSame('https://api.tunesa.app', PlatformUrl::apiBaseUrl('https://api.tunesa.app/'));
        $this->assertNull(PlatformUrl::apiBaseUrl(null));

        config(['app.url' => 'http://localhost']);
        $this->assertNull(PlatformUrl::apiBaseUrl(self::OLD), 'sin https no se anuncia nada');
    }

    public function test_app_config_announces_current_url_instead_of_stale_tunnel(): void
    {
        $acme = $this->makeTenant('acme');
        TenantSetting::editableRowFor($acme)->fill(['api_base_url' => self::OLD])->save();

        $this->getJson('/api/v1/app/config?tenant_slug=acme')->assertOk()
            ->assertJsonPath('data.mobile.api_base_url', self::NEW);
    }

    public function test_webhook_saved_with_old_tunnel_is_posted_to_current_host(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        $acme = $this->makeTenant('acme');
        $emp = $this->makeUser($acme, 'employee', ['employee_code' => 'EMP-001']);
        $emp->branches()->attach($this->makeBranch($acme)->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        TenantSetting::editableRowFor($acme)->fill([
            'webhook_url' => self::OLD . '/api/v1/webhooks/receiver/acme', 'webhook_secret' => 'whsec_x',
        ])->save();

        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', [
            'latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12, 'device_id' => 'd1',
            'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
        ])->assertCreated();

        $urls = Http::recorded()->map(fn ($pair) => $pair[0]->url())->all();
        $this->assertContains(self::NEW . '/api/v1/webhooks/receiver/acme', $urls);
        $this->assertEmpty(array_filter($urls, fn ($u) => str_contains($u, 'rio-seem')));
    }

    public function test_use_url_command_updates_env_and_saved_settings(): void
    {
        config(['app.url' => self::OLD]);
        $dir = sys_get_temp_dir() . '/tunesa-env-' . uniqid();
        mkdir($dir);
        file_put_contents("{$dir}/.env", "APP_NAME=Tunesa\nAPP_URL=" . self::OLD . "\n");
        $this->app->useEnvironmentPath($dir);

        $acme = $this->makeTenant('acme');
        TenantSetting::editableRowFor($acme)->fill([
            'api_base_url' => self::OLD,
            'webhook_url' => self::OLD . '/api/v1/webhooks/receiver/acme',
            'notification_endpoint' => self::OLD . '/api/v1/webhooks/receiver/acme?canal=notificaciones',
        ])->save();
        TenantSetting::editableRowFor(null)->fill(['api_base_url' => 'https://api.tunesa.app'])->save();

        $this->artisan('app:use-url', ['url' => self::NEW . '/api', '--dry-run' => true])->assertSuccessful();
        $this->assertStringContainsString('APP_URL=' . self::OLD, file_get_contents("{$dir}/.env"));
        $this->assertSame(self::OLD, TenantSetting::rowFor($acme)->api_base_url);

        $this->artisan('app:use-url', ['url' => self::NEW])->assertSuccessful();
        $this->assertStringContainsString('APP_URL=' . self::NEW . "\n", file_get_contents("{$dir}/.env"));
        $row = TenantSetting::rowFor($acme)->fresh();
        $this->assertSame(self::NEW, $row->api_base_url);
        $this->assertSame(self::NEW . '/api/v1/webhooks/receiver/acme', $row->webhook_url);
        $this->assertSame(self::NEW . '/api/v1/webhooks/receiver/acme?canal=notificaciones', $row->notification_endpoint);
        $this->assertSame('https://api.tunesa.app', TenantSetting::rowFor(null)->api_base_url, 'dominio fijo: no se toca');

        $this->artisan('app:use-url', ['url' => 'http://inseguro.test'])->assertFailed();

        @unlink("{$dir}/.env");
        @rmdir($dir);
    }
}
