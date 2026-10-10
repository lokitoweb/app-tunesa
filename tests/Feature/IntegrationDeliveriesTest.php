<?php

namespace Tests\Feature;

use App\Modules\Integrations\Controllers\Web\IntegrationsWebController;
use App\Modules\Integrations\Jobs\SendTenantWebhook;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\Integrations\Models\WebhookDelivery;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Trazabilidad de integraciones (webhook_deliveries), botones de prueba de
 * "Marca y app › Integraciones" y búsqueda de direcciones en sucursales.
 */
class IntegrationDeliveriesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const HOOK   = 'https://erp.example.test/hooks/asistencia';
    private const NOTIFY = 'https://avisos.example.test/push';
    private const SECRET = 'whsec_prueba_123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('public');
        Storage::fake('private');
    }

    private function configure(?Tenant $tenant, array $values): void
    {
        TenantSetting::editableRowFor($tenant)->fill($values)->save();
    }

    private function clockIn(Tenant $tenant)
    {
        $emp = $this->makeUser($tenant, 'employee', ['employee_code' => 'EMP-001']);
        $emp->branches()->attach($this->makeBranch($tenant)->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);

        return $this->actingIn($emp, $tenant)->post('/api/attendance/clock-in', [
            'latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12, 'device_id' => 'd1',
            'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
        ]);
    }

    /** Job "encolado" en una cola real (no sync) en el intento $attempt */
    private function queuedJob(Tenant $tenant, int $attempt): SendTenantWebhook
    {
        $job = new SendTenantWebhook($tenant->id, SendTenantWebhook::TARGET_WEBHOOK, 'attendance.clock_in', '{"event":"attendance.clock_in"}', 'b3f1c1d2-0000-4000-8000-000000000001');
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('getConnectionName')->andReturn('database');
        $queueJob->shouldReceive('attempts')->andReturn($attempt);
        $job->setJob($queueJob);

        return $job;
    }

    // =========================================================================
    // REGISTRO DE ENVÍOS
    // =========================================================================

    public function test_successful_delivery_is_recorded_with_signature_and_response(): void
    {
        Http::fake([self::HOOK => Http::response(['recibido' => true], 200)]);
        $acme = $this->makeTenant('acme');
        $this->configure($acme, ['webhook_url' => self::HOOK, 'webhook_secret' => self::SECRET]);

        $this->clockIn($acme)->assertCreated();

        $delivery = WebhookDelivery::sole();
        $sent     = Http::recorded()->first()[0];

        $this->assertSame($acme->id, $delivery->tenant_id);
        $this->assertSame('attendance.clock_in', $delivery->event);
        $this->assertSame(SendTenantWebhook::TARGET_WEBHOOK, $delivery->target);
        $this->assertSame(self::HOOK, $delivery->url);
        $this->assertSame(WebhookDelivery::STATUS_SUCCESS, $delivery->status);
        $this->assertSame(1, $delivery->attempt);
        $this->assertSame(200, $delivery->http_status);
        $this->assertNotNull($delivery->response_ms);
        $this->assertSame('{"recibido":true}', $delivery->response_body);
        $this->assertSame($sent->body(), $delivery->request_body);
        $this->assertSame('sha256=' . hash_hmac('sha256', $sent->body(), self::SECRET), $delivery->signature);
        $this->assertTrue($sent->hasHeader('X-Tunesa-Signature', $delivery->signature));
        $this->assertNull($delivery->next_retry_at);
    }

    public function test_failed_delivery_is_recorded_and_never_breaks_the_clock_in(): void
    {
        Http::fake([self::HOOK => Http::response('caído', 500)]);
        $acme = $this->makeTenant('acme');
        $this->configure($acme, ['webhook_url' => self::HOOK]);

        $this->clockIn($acme)->assertCreated();

        $delivery = WebhookDelivery::sole();
        $this->assertSame(WebhookDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame(500, $delivery->http_status);
        $this->assertSame('caído', $delivery->response_body);
        $this->assertStringContainsString('HTTP 500', $delivery->error);
        // Toda empresa nace con secreto: hasta los envíos fallidos van firmados
        $this->assertStringStartsWith('sha256=', $delivery->signature);
    }

    public function test_connection_errors_are_recorded(): void
    {
        Http::fake([self::HOOK => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds')]);
        $acme = $this->makeTenant('acme');
        $this->configure($acme, ['webhook_url' => self::HOOK]);

        $this->clockIn($acme)->assertCreated();

        $delivery = WebhookDelivery::sole();
        $this->assertSame(WebhookDelivery::STATUS_FAILED, $delivery->status);
        $this->assertNull($delivery->http_status);
        $this->assertSame('Sin respuesta dentro del tiempo límite.', $delivery->error);
    }

    public function test_a_pending_row_is_visible_while_the_job_waits_in_the_queue(): void
    {
        Queue::fake();
        $acme = $this->makeTenant('acme');
        $this->configure($acme, ['webhook_url' => self::HOOK]);

        $this->clockIn($acme)->assertCreated();

        $delivery = WebhookDelivery::sole();
        $this->assertSame(WebhookDelivery::STATUS_PENDING, $delivery->status);
        $this->assertSame(0, $delivery->attempt);
        Queue::assertPushed(SendTenantWebhook::class, fn (SendTenantWebhook $job) => $job->deliveryUuid === $delivery->uuid);
    }

    public function test_notification_endpoint_deliveries_are_recorded_too(): void
    {
        Http::fake();
        $acme = $this->makeTenant('acme');
        $this->makeUser($acme, 'hr_manager');
        $emp = $this->makeUser($acme);
        $this->configure($acme, ['notification_endpoint' => self::NOTIFY]);

        \App\Modules\HR\Models\LeaveRequest::create(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'type' => 'vacation',
            'start_date' => '2026-11-02', 'end_date' => '2026-11-03', 'days' => 2, 'reason' => 'Viaje', 'status' => 'pending']);

        $delivery = WebhookDelivery::where('target', SendTenantWebhook::TARGET_NOTIFICATION)->sole();
        $this->assertSame('notification.created', $delivery->event);
        $this->assertSame(WebhookDelivery::STATUS_SUCCESS, $delivery->status);
    }

    public function test_retries_use_exponential_backoff_and_record_next_retry_at(): void
    {
        $busy = Http::sequence();
        foreach (range(1, 5) as $i) {
            $busy->push('ocupado', 503);
        }
        Http::fake([self::HOOK => $busy->push('ok', 200)]);
        $acme = $this->makeTenant('acme');
        $this->configure($acme, ['webhook_url' => self::HOOK]);
        $this->freezeSecond();

        $this->assertSame(5, (new SendTenantWebhook($acme->id, 'webhook_url', 'x', '{}'))->tries);

        foreach ([1 => 10, 2 => 60, 3 => 300, 4 => 900] as $attempt => $wait) {
            try {
                $this->queuedJob($acme, $attempt)->handle(app(\App\Modules\Integrations\Services\WebhookSender::class));
                $this->fail('El job debe lanzar una excepción para que la cola lo reintente');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('HTTP 503', $e->getMessage());
            }

            $delivery = WebhookDelivery::sole();
            $this->assertSame(WebhookDelivery::STATUS_PENDING, $delivery->status);
            $this->assertSame($attempt, $delivery->attempt);
            $this->assertTrue(now()->addSeconds($wait)->equalTo($delivery->next_retry_at), "Intento {$attempt}: espera {$wait} s");
        }

        // Último intento: fallo definitivo, sin próximo reintento
        try {
            $this->queuedJob($acme, 5)->handle(app(\App\Modules\Integrations\Services\WebhookSender::class));
        } catch (RuntimeException) {
        }
        $delivery = WebhookDelivery::sole();
        $this->assertSame(WebhookDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame(5, $delivery->attempt);
        $this->assertNull($delivery->next_retry_at);

        // Si después responde bien, la misma fila queda como entregada
        $this->queuedJob($acme, 2)->handle(app(\App\Modules\Integrations\Services\WebhookSender::class));
        $this->assertSame(WebhookDelivery::STATUS_SUCCESS, WebhookDelivery::sole()->status);
    }

    public function test_failed_hook_marks_a_pending_delivery_as_failed(): void
    {
        $acme = $this->makeTenant('acme');
        $job  = $this->queuedJob($acme, 3);
        WebhookDelivery::create(['uuid' => $job->deliveryUuid, 'tenant_id' => $acme->id, 'target' => 'webhook_url',
            'event' => 'attendance.clock_in', 'url' => self::HOOK, 'status' => 'pending', 'next_retry_at' => now()->addMinute()]);

        $job->failed(new RuntimeException('Tiempo de ejecución agotado'));

        $delivery = WebhookDelivery::sole();
        $this->assertSame(WebhookDelivery::STATUS_FAILED, $delivery->status);
        $this->assertNull($delivery->next_retry_at);
        $this->assertSame('Tiempo de ejecución agotado', $delivery->error);
    }

    public function test_old_deliveries_are_pruned_daily(): void
    {
        $acme = $this->makeTenant('acme');
        $old  = WebhookDelivery::create(['uuid' => 'a0000000-0000-4000-8000-000000000001', 'tenant_id' => $acme->id, 'target' => 'webhook_url', 'event' => 'ping', 'url' => self::HOOK]);
        $old->forceFill(['created_at' => now()->subDays(31)])->save();
        $new  = WebhookDelivery::create(['uuid' => 'a0000000-0000-4000-8000-000000000002', 'tenant_id' => $acme->id, 'target' => 'webhook_url', 'event' => 'ping', 'url' => self::HOOK]);

        $this->artisan('model:prune', ['--model' => [WebhookDelivery::class]])->assertSuccessful();

        $this->assertSame([$new->id], WebhookDelivery::pluck('id')->all());
        $this->assertTrue(collect(app(Schedule::class)->events())->contains(
            fn ($event) => str_contains($event->command, 'model:prune') && str_contains($event->command, 'WebhookDelivery')
        ));
    }

    // =========================================================================
    // BOTONES DE PRUEBA
    // =========================================================================

    public function test_test_webhook_sends_a_signed_ping_to_the_typed_url_and_returns_json(): void
    {
        Http::fake(['https://nuevo.example.test/*' => Http::response(['pong' => true], 200)]);
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $this->configure($acme, ['webhook_url' => self::HOOK, 'webhook_secret' => self::SECRET]);

        $this->actingAs($admin)->postJson('/settings/branding/test-webhook', ['url' => 'https://nuevo.example.test/hook'])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 200, 'body' => '{"pong":true}', 'signed' => true])
            ->assertJsonStructure(['ok', 'status', 'ms', 'body', 'delivery_id']);

        Http::assertSent(function (Request $request) {
            $json = json_decode($request->body(), true);

            return $request->url() === 'https://nuevo.example.test/hook'
                && $request->hasHeader('X-Tunesa-Event', 'ping')
                && $request->hasHeader('X-Tunesa-Signature', 'sha256=' . hash_hmac('sha256', $request->body(), self::SECRET))
                && $json['event'] === 'ping'
                && $json['tenant'] === ['slug' => 'acme']
                && $json['data'] === ['message' => 'Prueba desde TuneSA']
                && isset($json['occurred_at']);
        });
        Http::assertNotSent(fn (Request $r) => $r->url() === self::HOOK);

        $delivery = WebhookDelivery::sole();
        $this->assertSame([$acme->id, 'ping', 'success', 200], [$delivery->tenant_id, $delivery->event, $delivery->status, $delivery->http_status]);
    }

    public function test_test_webhook_uses_the_saved_url_reports_failures_and_requires_a_url(): void
    {
        Http::fake([self::HOOK => Http::response('mantenimiento', 503)]);
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');

        $this->actingAs($admin)->postJson('/settings/branding/test-webhook')
            ->assertStatus(422)->assertJson(['ok' => false])->assertJsonPath('error', 'Escribe la URL del webhook para probarla.');

        $this->configure($acme, ['webhook_url' => self::HOOK]);
        $this->actingAs($admin)->postJson('/settings/branding/test-webhook', ['url' => ''])
            ->assertOk()
            ->assertJson(['ok' => false, 'status' => 503, 'signed' => true, 'body' => 'mantenimiento'])
            ->assertJsonPath('error', 'El destino respondió HTTP 503.');

        $this->actingAs($admin)->postJson('/settings/branding/test-webhook', ['url' => 'ftp://x.test'])->assertStatus(422);
    }

    public function test_test_webhook_refuses_private_network_addresses(): void
    {
        Http::fake();
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');

        foreach (['http://127.0.0.1:8080/admin', 'http://localhost/hook', 'http://169.254.169.254/latest/meta-data', 'http://10.0.0.5/x'] as $url) {
            $this->actingAs($admin)->postJson('/settings/branding/test-webhook', ['url' => $url])
                ->assertOk()->assertJson(['ok' => false, 'status' => null])
                ->assertJsonPath('error', 'La URL apunta a una red privada o local; usa una dirección pública.');
        }
        Http::assertNothingSent();

        config(['integrations.allow_private_urls' => true]);
        $this->actingAs($admin)->postJson('/settings/branding/test-webhook', ['url' => 'http://127.0.0.1:8080/hook'])->assertJson(['ok' => true]);
    }

    public function test_test_notification_sends_notification_test_event(): void
    {
        Http::fake([self::NOTIFY => Http::response('', 204)]);
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $this->configure($acme, ['notification_endpoint' => self::NOTIFY, 'webhook_secret' => self::SECRET]);

        $this->actingAs($admin)->postJson('/settings/branding/test-notification')->assertOk()->assertJson(['ok' => true, 'status' => 204]);

        Http::assertSent(fn (Request $r) => $r->url() === self::NOTIFY
            && $r->hasHeader('X-Tunesa-Event', 'notification.test')
            && json_decode($r->body(), true)['event'] === 'notification.test');
        $this->assertSame(SendTenantWebhook::TARGET_NOTIFICATION, WebhookDelivery::sole()->target);
    }

    public function test_super_admin_global_view_tests_and_lists_platform_rows(): void
    {
        Http::fake(['https://plataforma.example.test/*' => Http::response('ok')]);
        $acme = $this->makeTenant('acme');
        $root = $this->makeUser(null, 'super_admin');
        WebhookDelivery::create(['uuid' => 'c0000000-0000-4000-8000-000000000001', 'tenant_id' => $acme->id, 'target' => 'webhook_url', 'event' => 'attendance.clock_in', 'url' => self::HOOK]);

        $this->actingAs($root)->postJson('/settings/branding/test-webhook', ['url' => 'https://plataforma.example.test/hook'])
            ->assertOk()->assertJson(['ok' => true]);

        $this->actingAs($root)->getJson('/settings/branding/deliveries')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'ping')
            ->assertJsonPath('data.0.event_label', 'Prueba del webhook')
            ->assertJsonPath('data.0.host', 'plataforma.example.test');
        $this->assertNull(WebhookDelivery::where('event', 'ping')->sole()->tenant_id);
    }

    public function test_deliveries_are_scoped_to_the_company_and_require_permission(): void
    {
        $acme  = $this->makeTenant('acme');
        $beta  = $this->makeTenant('beta');
        $admin = $this->makeUser($acme, 'company_admin');
        $hr    = $this->makeUser($acme, 'hr_manager');

        foreach ([[$acme, 'attendance.clock_in'], [$beta, 'attendance.clock_out'], [null, 'ping']] as $i => [$tenant, $event]) {
            WebhookDelivery::create(['uuid' => "d0000000-0000-4000-8000-00000000000{$i}", 'tenant_id' => $tenant?->id,
                'target' => 'webhook_url', 'event' => $event, 'url' => self::HOOK, 'status' => 'failed',
                'http_status' => 500, 'response_ms' => 120, 'response_body' => 'error interno', 'attempt' => 2]);
        }

        $this->actingAs($admin)->getJson('/settings/branding/deliveries')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'attendance.clock_in')
            ->assertJsonPath('data.0.event_label', 'Marcación de entrada')
            ->assertJsonPath('data.0.host', 'erp.example.test')
            ->assertJsonPath('data.0.http_status', 500)
            ->assertJsonPath('data.0.attempt', 2)
            ->assertJsonPath('data.0.response_body', 'error interno');

        $this->actingAs($hr)->getJson('/settings/branding/deliveries')->assertForbidden();
        $this->actingAs($hr)->postJson('/settings/branding/test-webhook', ['url' => self::HOOK])->assertForbidden();
    }

    public function test_deliveries_list_is_limited_to_the_last_twenty(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        foreach (range(1, 25) as $i) {
            WebhookDelivery::create(['uuid' => sprintf('e0000000-0000-4000-8000-%012d', $i), 'tenant_id' => $acme->id,
                'target' => 'webhook_url', 'event' => "evento.{$i}", 'url' => self::HOOK]);
        }

        $this->actingAs($admin)->getJson('/settings/branding/deliveries')
            ->assertJsonCount(20, 'data')->assertJsonPath('data.0.event', 'evento.25');
    }

    // =========================================================================
    // PUSH DE PRUEBA (FcmSender lo crea el módulo push: aquí se simula)
    // =========================================================================

    private function fakeFcmSender(): Mockery\MockInterface
    {
        $class = IntegrationsWebController::FCM_SENDER;
        if (! class_exists($class)) {
            // Rama sin el módulo push: stub mínimo con la misma firma, solo para poder simularlo
            eval('namespace App\Modules\Integrations\Services\Push; class FcmSender { public function test(\App\Modules\Core\Models\User $to): array { return []; } }');
        }
        $mock = Mockery::mock($class);
        $this->app->instance($class, $mock);

        return $mock;
    }

    public function test_test_push_explains_that_the_user_has_no_device(): void
    {
        $this->fakeFcmSender()->shouldNotReceive('test');
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin', ['fcm_token' => null]);

        $this->actingAs($admin)->postJson('/settings/branding/test-push')
            ->assertOk()->assertJson(['ok' => false, 'no_device' => true])
            ->assertJsonPath('error', fn ($error) => str_contains($error, 'Inicia sesión en la app con este usuario para registrar el dispositivo'));
    }

    public function test_test_push_sends_to_the_current_user(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin', ['fcm_token' => 'token-del-telefono']);
        $this->fakeFcmSender()->shouldReceive('test')->once()
            ->withArgs(fn (User $to) => $to->is($admin))
            ->andReturn(['ok' => true, 'status' => 200, 'ms' => 321, 'response' => '{"name":"projects/x/messages/1"}']);

        $this->actingAs($admin)->postJson('/settings/branding/test-push')
            ->assertOk()->assertJson(['ok' => true, 'status' => 200, 'ms' => 321, 'body' => '{"name":"projects/x/messages/1"}']);
    }

    // =========================================================================
    // PANTALLAS
    // =========================================================================

    public function test_integrations_tab_renders_buttons_and_history_without_unused_notices(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');

        $this->actingAs($admin)->get('/settings/branding?tab=integraciones')->assertOk()
            ->assertSee('Probar Webhook Ahora')
            ->assertSee('Enviar Notificación de Prueba')
            ->assertSee('Últimos 20 envíos')
            ->assertSee('Probar geocodificación')
            ->assertSee(route('settings.branding.deliveries'))
            ->assertSee(route('settings.branding.test-webhook'))
            ->assertDontSee('no se usa');
    }

    public function test_branch_form_renders_the_address_search(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $branch = $this->makeBranch($acme, ['address' => 'Av. Larco 345']);

        foreach (['/branches/create', "/branches/{$branch->id}/edit"] as $page) {
            $this->actingAs($admin)->get($page)->assertOk()
                ->assertSee('id="addressSearch"', false)
                ->assertSee('role="combobox"', false)
                ->assertSee(url('/geocode/search'))
                ->assertSee(url('/geocode/reverse'))
                ->assertSee('Usar dirección sugerida')
                ->assertSee('id="radiusSlider"', false);
        }
    }

    // =========================================================================
    // CAMPOS NUEVOS (columnas del módulo core: se prueban si existen)
    // =========================================================================

    private function requireColumns(string ...$columns): void
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn('tenant_settings', $column)) {
                $this->markTestSkipped("tenant_settings.{$column} todavía no existe en esta rama");
            }
        }
    }

    private function serviceAccount(array $override = []): string
    {
        return json_encode(array_merge([
            'type'         => 'service_account',
            'project_id'   => 'tunesa-demo',
            'client_email' => 'firebase-adminsdk@tunesa-demo.iam.gserviceaccount.com',
            'private_key'  => "-----BEGIN PRIVATE KEY-----\nMIIabc\n-----END PRIVATE KEY-----\n",
        ], $override));
    }

    public function test_platform_saves_geocoding_antifraud_and_push_settings(): void
    {
        $this->requireColumns('geocoding_provider', 'mock_location_policy', 'max_time_drift_seconds', 'fcm_enabled', 'fcm_service_account_json');
        $root = $this->makeUser(null, 'super_admin');

        $this->actingAs($root)->put('/settings/branding', [
            'tab'                      => 'integraciones',
            'geocoding_provider'       => 'google',
            'mock_location_policy'     => 'flag',
            'max_time_drift_seconds'   => 120,
            'fcm_enabled'              => '1',
            'fcm_service_account_json' => $this->serviceAccount(),
        ])->assertSessionHasNoErrors()->assertRedirect(route('settings.branding', ['tab' => 'integraciones']));

        $row = TenantSetting::rowFor(null);
        $this->assertSame('google', $row->geocoding_provider);
        $this->assertSame('flag', $row->mock_location_policy);
        $this->assertSame(120, (int) $row->max_time_drift_seconds);
        $this->assertTrue((bool) $row->fcm_enabled);
        $this->assertSame('tunesa-demo', json_decode($row->fcm_service_account_json, true)['project_id']);

        $this->actingAs($root)->get('/settings/branding?tab=integraciones')->assertOk()
            ->assertSee('tunesa-demo')->assertDontSee('MIIabc');
    }

    public function test_service_account_json_is_validated_and_platform_only(): void
    {
        $this->requireColumns('fcm_service_account_json');
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $root  = $this->makeUser(null, 'super_admin');

        $this->actingAs($root)->put('/settings/branding', ['fcm_service_account_json' => '{no es json'])
            ->assertSessionHasErrors('fcm_service_account_json');
        $this->actingAs($root)->put('/settings/branding', ['fcm_service_account_json' => $this->serviceAccount(['client_email' => null])])
            ->assertSessionHasErrors(['fcm_service_account_json' => 'Al JSON de la cuenta de servicio le falta: client_email.']);

        // También se puede subir como archivo
        $file = UploadedFile::fake()->createWithContent('cuenta.json', $this->serviceAccount());
        $this->actingAs($root)->put('/settings/branding', ['fcm_service_account_file' => $file])->assertSessionHasNoErrors();
        $this->assertNotNull(TenantSetting::rowFor(null)->fcm_service_account_json);

        // Una empresa no puede cambiarla: se ignora
        $this->actingAs($admin)->put('/settings/branding', [
            'primary_color' => '#1E40AF', 'secondary_color' => '#F59E0B',
            'fcm_service_account_json' => $this->serviceAccount(['project_id' => 'otra']),
        ])->assertSessionHasNoErrors();
        $this->assertNull(TenantSetting::rowFor($acme)?->fcm_service_account_json);
        $this->assertSame('tunesa-demo', json_decode(TenantSetting::rowFor(null)->fcm_service_account_json, true)['project_id']);

        $this->actingAs($admin)->get('/settings/branding?tab=integraciones')->assertOk()
            ->assertSee('Configurado por la plataforma')->assertDontSee('tunesa-demo');
    }

    public function test_company_can_inherit_the_new_settings_with_null(): void
    {
        $this->requireColumns('geocoding_provider', 'mock_location_policy', 'fcm_enabled');
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $base  = ['primary_color' => '#1E40AF', 'secondary_color' => '#F59E0B'];

        $this->actingAs($admin)->put('/settings/branding', $base + ['geocoding_provider' => 'google', 'mock_location_policy' => 'reject', 'fcm_enabled' => '0'])
            ->assertSessionHasNoErrors();
        $row = TenantSetting::rowFor($acme);
        $this->assertSame(['google', 'reject', false], [$row->geocoding_provider, $row->mock_location_policy, (bool) $row->fcm_enabled]);

        $this->actingAs($admin)->put('/settings/branding', $base + ['geocoding_provider' => '', 'mock_location_policy' => '', 'fcm_enabled' => ''])
            ->assertSessionHasNoErrors();
        $row->refresh();
        $this->assertSame([null, null, null], [$row->geocoding_provider, $row->mock_location_policy, $row->fcm_enabled]);
    }
}
