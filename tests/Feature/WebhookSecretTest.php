<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\TenantSetting;
use App\Services\Integrations\WebhookSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Integraciones nunca vacías: secreto autogenerado, regenerable, y URLs
 * precargadas con el receptor de la plataforma (que verifica la firma).
 */
class WebhookSecretTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_every_new_company_gets_a_secure_secret_encrypted_at_rest(): void
    {
        $acme   = $this->makeTenant('acme');
        $secret = TenantSetting::rowFor($acme)->webhook_secret;

        $this->assertMatchesRegularExpression('/^whsec_[A-Za-z0-9]{32}$/', $secret);
        $this->assertStringNotContainsString($secret, (string) DB::table('tenant_settings')->where('tenant_id', $acme->id)->value('webhook_secret'));
        $this->assertNotSame($secret, TenantSetting::rowFor($this->makeTenant('other'))->webhook_secret);
    }

    public function test_panel_generates_a_missing_secret_and_prefills_the_receiver_urls(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        DB::table('tenant_settings')->where('tenant_id', $acme->id)->update(['webhook_secret' => null]); // dato antiguo

        $html = $this->actingAs($admin)->get('/settings/branding?tab=integraciones')->assertOk()
            ->assertDontSee('Sin secreto')->assertSee('Regenerar secreto')
            ->assertSee('value="' . route('integrations.receiver', ['slug' => 'acme']) . '"', false)
            ->assertSee('value="' . route('integrations.receiver', ['slug' => 'acme']) . '?canal=notificaciones"', false)
            ->getContent();

        $secret = TenantSetting::rowFor($acme)->webhook_secret;
        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertStringContainsString('value="' . $secret . '"', $html);
    }

    public function test_regenerate_returns_a_new_secret_and_audits_without_the_value(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $old   = TenantSetting::rowFor($acme)->webhook_secret;

        $new = $this->actingAs($admin)->postJson('/settings/branding/regenerate-webhook-secret')
            ->assertOk()->assertJson(['success' => true])->json('secret');

        $this->assertMatchesRegularExpression('/^whsec_[A-Za-z0-9]{32}$/', $new);
        $this->assertNotSame($old, $new);
        $this->assertSame($new, TenantSetting::rowFor($acme)->webhook_secret);

        $log = AuditLog::where('action', 'integration.webhook_secret_regenerated')->sole();
        $this->assertStringNotContainsString('whsec_', json_encode($log->meta));

        // Sin permiso de configurar la empresa no se regenera
        $supervisor = $this->makeUser($acme, 'supervisor');
        $this->assertFalse($this->actingAs($supervisor)->postJson('/settings/branding/regenerate-webhook-secret')->isOk());
        $this->assertSame($new, TenantSetting::rowFor($acme)->webhook_secret);
    }

    public function test_receiver_accepts_only_the_company_signature(): void
    {
        $acme   = $this->makeTenant('acme');
        $secret = TenantSetting::rowFor($acme)->webhook_secret;
        $body   = '{"event":"ping"}';
        $post   = fn (array $headers) => $this->call('POST', '/api/v1/webhooks/receiver/acme', [], [], [], $headers + ['CONTENT_TYPE' => 'application/json'], $body);

        $post(['HTTP_X_TUNESA_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $body, $secret), 'HTTP_X_TUNESA_EVENT' => 'ping'])
            ->assertOk()->assertJson(['ok' => true, 'signature_valid' => true, 'event' => 'ping']);
        $post(['HTTP_X_TUNESA_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $body, 'otro')])
            ->assertStatus(401)->assertJsonPath('message', 'La firma no coincide con el secreto de la empresa.');
        $post([])->assertStatus(401)->assertJsonPath('message', 'Falta la firma X-Tunesa-Signature.');
        $this->postJson('/api/v1/webhooks/receiver/no-existe', [])->assertNotFound();
    }

    public function test_prefilled_receiver_url_can_be_tested_with_the_saved_secret(): void
    {
        $acme     = $this->makeTenant('acme');
        $admin    = $this->makeUser($acme, 'company_admin');
        $receiver = route('integrations.receiver', ['slug' => 'acme']);
        Http::fake([$receiver => Http::response(['ok' => true, 'signature_valid' => true], 200)]);

        // localhost (APP_URL) se permite solo para la ruta del receptor
        $this->actingAs($admin)->postJson('/settings/branding/test-webhook', ['url' => $receiver])
            ->assertOk()->assertJson(['ok' => true, 'status' => 200, 'signed' => true]);

        $secret = TenantSetting::rowFor($acme)->webhook_secret;
        Http::assertSent(fn (Request $r) => $r->url() === $receiver
            && $r->header('X-Tunesa-Signature')[0] === 'sha256=' . hash_hmac('sha256', $r->body(), $secret));
    }

    public function test_ssl_is_verified_only_in_production_and_tests_time_out_quickly(): void
    {
        // Local / pruebas: sin verificar el certificado (túneles de Cloudflare, certificados propios)
        $this->assertFalse(WebhookSender::verifiesSsl());
        $this->assertFalse(WebhookSender::requestOptions()['verify']);
        $this->assertFalse(WebhookSender::requestOptions()['allow_redirects']);
        config(['integrations.verify_ssl' => true]);
        $this->assertTrue(WebhookSender::verifiesSsl());

        // Producción: verificación estricta aunque INTEGRATIONS_VERIFY_SSL diga lo contrario
        $this->app['env'] = 'production';
        config(['integrations.verify_ssl' => false]);
        $this->assertTrue(WebhookSender::requestOptions()['verify']);
        $this->app['env'] = 'testing';

        // Los botones de prueba esperan 5 s como máximo (los envíos reales, 10 s)
        $this->assertSame(5, config('integrations.test_timeout'));
        $this->assertSame(10, config('integrations.timeout'));
    }
}
