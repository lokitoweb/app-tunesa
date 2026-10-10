<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Secretos enmascarados en todo el panel y en la bitácora; errores 5xx sin
 * detalles internos (mensaje, SQL, IP) cuando APP_DEBUG=false.
 */
class SecretsAndErrorLeaksTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const WEBHOOK = 'whsec_LiveSecret_4f9a2c7e';
    private const MAPS    = 'AIzaSyFakeKey_ZZ99xy';
    private const ACCOUNT = '19100088877766';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_secrets_never_appear_in_clear_in_panel_pages_api_or_audit(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $emp   = $this->makeUser($acme, 'employee');
        $this->makeBranch($acme);

        // Cambios por panel y API: quedan en la bitácora
        $this->actingAs($admin)->put('/settings/branding', [
            'primary_color' => '#1E40AF', 'secondary_color' => '#F59E0B',
            'webhook_secret' => self::WEBHOOK, 'maps_api_key' => self::MAPS, 'default_grace_minutes' => 7,
        ])->assertSessionHasNoErrors();
        $this->actingIn($admin, $acme)->putJson("/api/admin/employees/{$emp->id}/profile", ['bank_account_number' => self::ACCOUNT])->assertOk();
        $this->assertSame(self::WEBHOOK, TenantSetting::rowFor($acme)->webhook_secret);
        $this->assertSame(self::ACCOUNT, EmployeeProfile::where('user_id', $emp->id)->first()->bank_account_number);

        $pages = ['/dashboard', '/settings/branding', '/settings/branding?tab=integraciones', '/settings/branding?tab=app',
            '/settings/mobile', '/audit', '/employees', "/employees/{$emp->id}/file", '/branches', '/payroll'];
        foreach ($pages as $page) {
            $html = $this->actingAs($admin)->get($page)->assertOk()->getContent();
            // El secreto del webhook solo va precargado en su campo de contraseña de Integraciones
            if (str_starts_with($page, '/settings/branding')) {
                $this->assertSame(1, substr_count($html, self::WEBHOOK), "{$page} repite el secreto del webhook");
                $this->assertMatchesRegularExpression('/<input type="password" id="webhook_secret"[^>]*value="' . preg_quote(self::WEBHOOK, '/') . '"/s', $html);
                $html = str_replace(self::WEBHOOK, '', $html);
            }
            foreach ([self::WEBHOOK, self::MAPS, self::ACCOUNT, 'LiveSecret', 'FakeKey'] as $secret) {
                $this->assertStringNotContainsString($secret, $html, "{$page} muestra un secreto en claro");
            }
        }

        $json = $this->actingIn($admin, $acme)->getJson('/api/admin/app-config')->assertOk()->getContent();
        $this->assertStringNotContainsString('LiveSecret', $json);
        $this->assertStringNotContainsString('FakeKey', $json);

        // Ninguna fila de la bitácora guarda el valor en claro
        $this->assertGreaterThan(0, AuditLog::count());
        foreach (AuditLog::all() as $log) {
            $meta = json_encode($log->meta, JSON_UNESCAPED_UNICODE);
            foreach (['LiveSecret', 'FakeKey', '88877766', '$2y$'] as $secret) {
                $this->assertStringNotContainsString($secret, (string) $meta, "{$log->action} guarda un secreto");
            }
        }
    }

    public function test_audit_logger_scrubs_secrets_from_free_form_context(): void
    {
        $acme = $this->makeTenant('acme');
        app(\App\Services\AuditLogger::class)->log('tenant.updated', $acme, [
            'webhook_secret' => self::WEBHOOK, 'nested' => ['maps_api_key' => self::MAPS, 'password' => 'Secret123'],
            'fcm_service_account_json' => '{"private_key":"x"}', 'api_token' => 'tok_123456789',
        ]);

        $meta = json_encode(AuditLog::latest('id')->first()->meta);
        foreach (['LiveSecret', 'FakeKey', 'Secret123', 'private_key', 'tok_123456789'] as $secret) {
            $this->assertStringNotContainsString($secret, $meta);
        }
    }

    public function test_api_500_has_a_generic_shape_without_internal_details(): void
    {
        config(['app.debug' => false]);
        Route::middleware('api')->get('api/_test/boom', fn () => throw new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused (10.0.3.17:3306) /var/www/app/Services/X.php'));
        Route::middleware('api')->get('api/_test/abort503', fn () => throw new HttpException(503, 'redis at 172.18.0.4 down'));

        foreach (['/api/_test/boom' => 500, '/api/_test/abort503' => 503] as $uri => $status) {
            $res = $this->getJson($uri)->assertStatus($status)->assertExactJson([
                'success' => false,
                'message' => 'Ocurrió un error inesperado. Intenta de nuevo en unos minutos.',
                'code'    => 'SERVER_ERROR',
            ]);
            foreach (['SQLSTATE', '10.0.3.17', '172.18.0.4', '/var/www', 'RuntimeException', 'trace'] as $leak) {
                $this->assertStringNotContainsString($leak, $res->getContent());
            }
        }

        // Los 4xx conservan su forma
        $this->getJson('/api/leave-requests')->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_web_500_page_does_not_leak_exception_details(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('_test/boom', fn () => throw new \RuntimeException('Connection refused (10.0.3.17:3306)'));

        $this->get('/_test/boom')->assertStatus(500)
            ->assertSee('Ocurrió un error inesperado')
            ->assertDontSee('10.0.3.17')->assertDontSee('Connection refused')->assertDontSee('RuntimeException');
    }
}
