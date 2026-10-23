<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\Integrations\Notifications\Channels\FcmChannel;
use App\Modules\HR\Notifications\LeaveRequestReviewed;
use App\Modules\Integrations\Services\Push\FcmSender;
use App\Modules\Integrations\Services\Push\PushException;
use App\Modules\Core\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Push FCM HTTP v1: JWT de la cuenta de servicio, canje OAuth, envío y limpieza de tokens */
class FcmPushTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const SEND_URL = 'https://fcm.googleapis.com/v1/projects/tunesa-app/messages:send';

    private string $privateKey;

    private string $publicKey;

    private Tenant $acme;

    private User $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        [$this->privateKey, $this->publicKey] = $this->makeRsaKeyPair();

        $this->acme = $this->makeTenant('acme');
        $this->emp  = $this->makeUser($this->acme, 'employee', ['fcm_token' => 'device-token-123']);
        app(TenantContext::class)->clear();
    }

    private function serviceAccount(array $overrides = []): string
    {
        return json_encode(array_merge([
            'type'           => 'service_account',
            'project_id'     => 'tunesa-app',
            'private_key_id' => 'abc123',
            'private_key'    => $this->privateKey,
            'client_email'   => 'firebase-adminsdk@tunesa-app.iam.gserviceaccount.com',
            'token_uri'      => 'https://oauth2.googleapis.com/token',
        ], $overrides));
    }

    private function configurePlatform(bool $enabled = true): void
    {
        TenantSetting::editableRowFor(null)->fill([
            'fcm_service_account_json' => $this->serviceAccount(),
            'fcm_enabled'              => $enabled,
        ])->save();
    }

    private function fakeGoogle(array $sendResponse = ['name' => 'projects/tunesa-app/messages/0:1'], int $sendStatus = 200): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.token', 'expires_in' => 3599, 'token_type' => 'Bearer']),
            'fcm.googleapis.com/*'        => Http::response($sendResponse, $sendStatus),
        ]);
    }

    private function sender(): FcmSender
    {
        return app(FcmSender::class);
    }

    public function test_jwt_is_rs256_signed_by_the_service_account(): void
    {
        $account = TenantSetting::parseServiceAccount($this->serviceAccount());
        $jwt     = $this->sender()->buildJwt($account, 1_760_000_000);

        [$h, $c, $sig] = explode('.', $jwt);
        $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));

        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], json_decode($decode($h), true));
        $this->assertSame([
            'iss'   => 'firebase-adminsdk@tunesa-app.iam.gserviceaccount.com',
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => 1_760_000_000,
            'exp'   => 1_760_003_600,
        ], json_decode($decode($c), true));
        $this->assertSame(1, openssl_verify("{$h}.{$c}", $decode($sig), $this->publicKey, OPENSSL_ALGO_SHA256));
        $this->assertStringNotContainsString('=', $jwt);
    }

    public function test_access_token_exchange_is_cached(): void
    {
        $this->fakeGoogle();
        $this->configurePlatform();
        $account = $this->sender()->credentials();

        $this->assertSame('ya29.token', $this->sender()->accessToken($account));
        $this->assertSame('ya29.token', $this->sender()->accessToken($account));
        Http::assertSentCount(1);

        Http::assertSent(function (Request $r) {
            if ($r->url() !== 'https://oauth2.googleapis.com/token') {
                return false;
            }
            [$h, $c, $sig] = explode('.', $r['assertion']);

            return $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && $r->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
                && openssl_verify("{$h}.{$c}", base64_decode(strtr($sig, '-_', '+/')), $this->publicKey, OPENSSL_ALGO_SHA256) === 1;
        });
    }

    public function test_rejected_credentials_raise_a_clear_error(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'], 400)]);
        $this->configurePlatform();

        $this->expectException(PushException::class);
        $this->expectExceptionMessage('Invalid JWT Signature.');
        $this->sender()->accessToken($this->sender()->credentials());
    }

    public function test_send_posts_a_v1_message_with_string_data(): void
    {
        $this->fakeGoogle();
        $this->configurePlatform();

        $result = $this->sender()->send($this->emp, 'Hola', 'Cuerpo', ['leave_request_id' => 14, 'approved' => true, 'meta' => ['a' => 1], 'none' => null]);

        $this->assertTrue($result->ok);
        $this->assertSame(200, $result->status);
        Http::assertSent(function (Request $r) {
            $m = $r['message'] ?? null;

            return $r->url() === self::SEND_URL
                && $r->hasHeader('Authorization', 'Bearer ya29.token')
                && $m['token'] === 'device-token-123'
                && $m['notification'] === ['title' => 'Hola', 'body' => 'Cuerpo']
                && $m['data'] === ['leave_request_id' => '14', 'approved' => 'true', 'meta' => '{"a":1}']
                && $m['android']['priority'] === 'high'
                && $m['android']['notification']['channel_id'] === 'tunesa_default';
        });
    }

    public function test_unregistered_token_is_cleared(): void
    {
        $this->fakeGoogle(['error' => ['code' => 404, 'message' => 'Requested entity was not found.', 'status' => 'NOT_FOUND',
            'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]], 404);
        $this->configurePlatform();

        $result = $this->sender()->send($this->emp, 'Hola', 'Cuerpo');

        $this->assertFalse($result->ok);
        $this->assertTrue($result->tokenCleared);
        $this->assertSame('UNREGISTERED', $result->code);
        $this->assertNull($this->emp->fresh()->fcm_token);
    }

    public function test_unregistered_detail_with_400_also_clears_the_token(): void
    {
        $this->fakeGoogle(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'bad',
            'details' => [['errorCode' => 'UNREGISTERED']]]], 400);
        $this->configurePlatform();

        $this->assertTrue($this->sender()->send($this->emp, 'a', 'b')->tokenCleared);
        $this->assertNull($this->emp->fresh()->fcm_token);
    }

    public function test_other_errors_keep_the_token_and_report_transient_failures(): void
    {
        $this->fakeGoogle(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'Invalid JSON payload']], 400);
        $this->configurePlatform();

        $result = $this->sender()->send($this->emp, 'a', 'b');
        $this->assertFalse($result->ok);
        $this->assertFalse($result->transient);
        $this->assertSame('INVALID_ARGUMENT', $result->code);
        $this->assertSame('Invalid JSON payload', $result->error);
        $this->assertSame('device-token-123', $this->emp->fresh()->fcm_token);
    }

    public function test_server_errors_are_transient(): void
    {
        $this->fakeGoogle(['error' => ['code' => 503, 'status' => 'UNAVAILABLE', 'message' => 'later']], 503);
        $this->configurePlatform();

        $result = $this->sender()->send($this->emp, 'a', 'b');
        $this->assertTrue($result->transient);
        $this->assertSame(503, $result->status);
        $this->assertSame('device-token-123', $this->emp->fresh()->fcm_token);
    }

    public function test_expired_access_token_is_renewed_once(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'old', 'expires_in' => 3599])
                ->push(['access_token' => 'new', 'expires_in' => 3599]),
            'fcm.googleapis.com/*' => fn (Request $r) => $r->hasHeader('Authorization', 'Bearer old')
                ? Http::response(['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED']], 401)
                : Http::response(['name' => 'ok']),
        ]);
        $this->configurePlatform();

        $this->assertTrue($this->sender()->send($this->emp, 'a', 'b')->ok);
        $this->assertSame('new', $this->sender()->accessToken($this->sender()->credentials()), 'queda cacheado el token renovado');
        Http::assertSentCount(4); // token viejo, envío 401, token nuevo, envío OK
    }

    public function test_test_method_reports_ok_status_and_latency(): void
    {
        $this->fakeGoogle();
        $this->configurePlatform(enabled: false); // la prueba no depende de fcm_enabled

        $result = $this->sender()->test($this->emp);

        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['status']);
        $this->assertIsInt($result['ms']);
        $this->assertSame(['name' => 'projects/tunesa-app/messages/0:1'], $result['response']);
        $this->assertArrayNotHasKey('error', $result);
        Http::assertSent(fn (Request $r) => $r->url() === self::SEND_URL && $r['message']['data']['type'] === 'push.test');
    }

    public function test_test_method_explains_missing_configuration(): void
    {
        $result = $this->sender()->test($this->emp);
        $this->assertFalse($result['ok']);
        $this->assertNull($result['status']);
        $this->assertSame('FCM_NOT_CONFIGURED', $result['code']);
        $this->assertStringContainsString('cuenta de servicio', $result['error']);

        $this->configurePlatform();
        $this->emp->forceFill(['fcm_token' => null])->save();
        $this->assertSame('NO_DEVICE_TOKEN', $this->sender()->test($this->emp->fresh())['code']);
        Http::assertNothingSent();
    }

    public function test_app_notifications_also_go_to_push_when_enabled(): void
    {
        $this->fakeGoogle();
        $leave = $this->leaveRequest();

        // Sin fcm_enabled: solo in-app
        $this->assertSame(['database'], (new LeaveRequestReviewed($leave))->via($this->emp));

        $this->configurePlatform(enabled: true);
        $this->assertContains(FcmChannel::class, (new LeaveRequestReviewed($leave))->via($this->emp));

        // La empresa puede apagarlo aunque la plataforma lo tenga activo (NULL = heredar)
        TenantSetting::editableRowFor($this->acme)->fill(['fcm_enabled' => false])->save();
        $this->assertNotContains(FcmChannel::class, (new LeaveRequestReviewed($leave))->via($this->emp));
        TenantSetting::editableRowFor($this->acme)->fill(['fcm_enabled' => null])->save();

        // withoutPush() y usuarios sin token: sin push
        $this->assertNotContains(FcmChannel::class, (new LeaveRequestReviewed($leave))->withoutPush()->via($this->emp));
        $this->assertNotContains(FcmChannel::class, (new LeaveRequestReviewed($leave))->via($this->makeUser($this->acme)));

        // Aviso real: base de datos + push con el mismo id
        app(TenantContext::class)->clear();
        $this->emp->notify(new LeaveRequestReviewed($leave));

        $stored = $this->emp->notifications()->sole();
        Http::assertSent(fn (Request $r) => $r->url() === self::SEND_URL
            && $r['message']['notification']['title'] === 'Solicitud aprobada'
            && $r['message']['data']['type'] === 'leave_request.approved'
            && $r['message']['data']['notification_id'] === $stored->id
            && $r['message']['data']['screen'] === 'leave_requests');
    }

    public function test_settings_service_saves_fcm_only_on_the_platform(): void
    {
        $root = $this->makeUser(null, 'super_admin');
        $svc  = app(\App\Modules\Core\Services\TenantSettingsService::class);

        $this->assertSame(['prohibited'], $svc->rules(global: false)['fcm_service_account_json']);

        $v = validator(['fcm_service_account_json' => '{"project_id":"x"}'], $svc->rules(global: true));
        $this->assertTrue($v->fails());

        $request = \Illuminate\Http\Request::create('/', 'POST', [
            'fcm_service_account_json' => $this->serviceAccount(),
            'fcm_enabled'              => true,
            'google_maps_api_key'      => 'AIzaNueva',
            'geocoding_provider'       => 'google',
            'mock_location_policy'     => 'flag',
            'max_time_drift_seconds'   => 600,
        ]);
        $data = validator($request->all(), $svc->rules(global: true))->validate();
        $svc->update(null, $data, $request, $root);

        $platform = TenantSetting::rowFor(null);
        $this->assertSame('tunesa-app', TenantSetting::parseServiceAccount($platform->fcm_service_account_json)['project_id']);
        $this->assertSame('AIzaNueva', $platform->maps_api_key);
        $this->assertSame('flag', $platform->mock_location_policy);
        $this->assertSame(600, $platform->max_time_drift_seconds);
        $this->assertTrue(TenantSetting::forTenant($this->acme)->fcmEnabled());

        $payload = $svc->editablePayload(null);
        $this->assertTrue($payload['has_fcm_service_account']);
        $this->assertSame('tunesa-app', $payload['fcm_project_id']);
        $this->assertSame('••••••••ueva', $payload['google_maps_api_key']);
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode($payload));

        // La API pública nunca expone la cuenta de servicio
        $this->getJson('/api/v1/app/config')->assertOk()->assertJsonPath('data.mobile.fcm_enabled', true)
            ->assertDontSee('PRIVATE KEY')->assertDontSee('AIzaNueva');

        // Una empresa no puede subirla por la API de administración
        $admin = $this->makeUser($this->acme, 'company_admin');
        $this->actingIn($admin, $this->acme)->putJson('/api/admin/app-config', ['fcm_service_account_json' => $this->serviceAccount()])
            ->assertStatus(422);
        $this->actingIn($admin, $this->acme)->putJson('/api/admin/app-config', ['fcm_enabled' => false, 'google_maps_api_key' => 'AIzaEmpresa', 'geocoding_provider' => 'google'])
            ->assertOk()->assertJsonPath('data.effective.mobile.fcm_enabled', false)
            ->assertJsonPath('data.effective.mobile.geocoding_provider', 'google')
            ->assertJsonPath('data.settings.has_google_maps_api_key', true);
        $this->assertSame('AIzaEmpresa', TenantSetting::rowFor($this->acme)->maps_api_key);

        // clear_google_maps_api_key borra la clave
        $this->actingIn($admin, $this->acme)->putJson('/api/admin/app-config', ['clear_google_maps_api_key' => true])->assertOk();
        $this->assertNull(TenantSetting::rowFor($this->acme)->maps_api_key);
    }

    public function test_panel_edits_push_and_policies_and_sends_a_test_push(): void
    {
        $this->fakeGoogle();
        $root = $this->makeUser(null, 'super_admin');

        // Vista global: campos nuevos visibles; la cuenta de servicio se sube como archivo
        $this->actingAs($root)->get('/settings/integrations')->assertOk()
            ->assertInertia(fn ($page) => $page->where('isGlobal', true)
                ->where('has.fcm_account', true)->where('has.fcm_enabled', true)->where('has.geocoding_provider', true));
        $this->actingAs($root)->get('/settings/branding?tab=reglas')->assertOk()
            ->assertSee('name="mock_location_policy"', false)->assertSee('name="max_time_drift_seconds"', false);

        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('firebase.json', $this->serviceAccount());
        $this->actingAs($root)->put('/settings/integrations', [
            'fcm_enabled' => '1', 'fcm_service_account_file' => $file, 'geocoding_provider' => 'nominatim',
        ])->assertRedirect('/settings/integrations')->assertSessionHasNoErrors();
        $this->actingAs($root)->put('/settings/branding', ['tab' => 'reglas', 'mock_location_policy' => 'flag', 'max_time_drift_seconds' => 120])
            ->assertRedirect('/settings/branding?tab=reglas')->assertSessionHasNoErrors();

        $platform = TenantSetting::rowFor(null);
        $this->assertTrue($platform->fcm_enabled);
        $this->assertSame('flag', $platform->mock_location_policy);
        $this->assertSame(120, $platform->max_time_drift_seconds);
        $this->assertTrue(TenantSetting::forTenant($this->acme)->fcmEnabled());
        $this->actingAs($root)->get('/settings/integrations')->assertPageSees('tunesa-app')->assertPageMisses('PRIVATE KEY');

        // Empresa: selectores con "Heredar"; "" vuelve a NULL
        $admin = $this->makeUser($this->acme, 'company_admin');
        $this->actingAs($admin)->get('/settings/integrations')->assertOk()
            ->assertInertia(fn ($page) => $page->where('isGlobal', false)->where('values.fcm_enabled', ''))
            ->assertPageMisses('tunesa-app');
        $this->actingAs($admin)->put('/settings/branding', ['mock_location_policy' => 'reject', 'fcm_enabled' => '0'])
            ->assertSessionHasNoErrors();
        $this->assertSame('reject', TenantSetting::forTenant($this->acme)->mockLocationPolicy());
        $this->assertFalse(TenantSetting::forTenant($this->acme)->fcmEnabled());
        $this->actingAs($admin)->put('/settings/branding', ['mock_location_policy' => '', 'fcm_enabled' => ''])
            ->assertSessionHasNoErrors();
        $this->assertNull(TenantSetting::rowFor($this->acme)->mock_location_policy);
        $this->assertSame('flag', TenantSetting::forTenant($this->acme)->mockLocationPolicy());

        // Probar push: a un colaborador de la empresa (aunque la empresa lo tenga apagado)
        $this->actingAs($admin)->postJson('/settings/branding/test-push', ['email' => $this->emp->email])
            ->assertOk()->assertJson(['ok' => true, 'status' => 200]);
        Http::assertSent(fn (Request $r) => $r->url() === self::SEND_URL
            && $r['message']['token'] === 'device-token-123' && $r['message']['data']['type'] === 'push.test');
        $this->assertTrue(\App\Modules\Core\Models\AuditLog::withoutGlobalScopes()->where('action', 'push.tested')->where('subject_id', $this->emp->id)->exists());

        // Sin token propio: error explicado; usuario de otra empresa: no existe
        $this->actingAs($admin)->postJson('/settings/branding/test-push')->assertOk()->assertJson(['ok' => false, 'no_device' => true]);
        app(TenantContext::class)->clear();
        $other = $this->makeUser($this->makeTenant('globex'), 'employee', ['fcm_token' => 'x']);
        $this->actingAs($admin)->postJson('/settings/branding/test-push', ['email' => $other->email])
            ->assertOk()->assertJson(['ok' => false, 'error' => 'No hay un usuario con ese correo en esta empresa.']);
    }

    public function test_registering_a_token_dates_it_and_takes_it_from_whoever_had_it(): void
    {
        $other = $this->makeUser($this->acme, 'employee', ['fcm_token' => 'shared-phone']);
        app(TenantContext::class)->clear();

        $this->actingIn($this->emp, $this->acme)->postJson('/api/auth/refresh-fcm', ['fcm_token' => 'shared-phone'])->assertOk();

        $this->assertSame('shared-phone', $this->emp->fresh()->fcm_token);
        $this->assertNotNull($this->emp->fresh()->fcm_token_updated_at);
        // El celular ya es de otra cuenta: sus avisos no deben llegar ahí
        $this->assertNull($other->fresh()->fcm_token);
    }

    public function test_phone_can_send_itself_a_test_push(): void
    {
        $this->fakeGoogle();
        $this->configurePlatform(enabled: false);

        // Manda su token actual: queda registrado y la prueba va a ese teléfono
        $this->actingIn($this->emp, $this->acme)->postJson('/api/auth/test-push', ['fcm_token' => 'fresh-token'])
            ->assertOk()->assertJson(['success' => true]);
        $this->assertSame('fresh-token', $this->emp->fresh()->fcm_token);
        Http::assertSent(fn (Request $r) => $r->url() === self::SEND_URL
            && $r['message']['token'] === 'fresh-token' && $r['message']['data']['type'] === 'push.test'
            && $r['message']['android']['notification']['channel_id'] === 'tunesa_default');

        // Sin token en ningún lado: error explicado
        app(TenantContext::class)->clear();
        $bare = $this->makeUser($this->acme, 'employee');
        $this->actingIn($bare, $this->acme)->postJson('/api/auth/test-push')
            ->assertStatus(422)->assertJson(['success' => false, 'code' => 'NO_DEVICE_TOKEN']);
    }

    public function test_phone_test_push_reports_firebase_errors(): void
    {
        $this->fakeGoogle(['error' => ['status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.']], 404);
        $this->configurePlatform();

        $this->actingIn($this->emp, $this->acme)->postJson('/api/auth/test-push')
            ->assertStatus(422)->assertJson(['success' => false, 'code' => 'PUSH_FAILED']);
        $this->assertNull($this->emp->fresh()->fcm_token);
    }

    public function test_panel_test_push_explains_what_http_200_means(): void
    {
        $this->fakeGoogle(['name' => 'projects/tunesa-app/messages/0:99']);
        $this->configurePlatform();
        $this->emp->forceFill(['fcm_token_updated_at' => now()->subHours(3)])->save();
        $root = $this->makeUser(null, 'super_admin');

        $body = $this->actingAs($root)->postJson('/settings/branding/test-push', ['email' => $this->emp->email])
            ->assertOk()->assertJson(['ok' => true, 'status' => 200])->json('body');

        $this->assertStringContainsString("Google aceptó el aviso para {$this->emp->email}.", $body);
        $this->assertStringContainsString('Celular registrado el', $body);
        $this->assertStringContainsString('Perfil > Notificaciones', $body);
        $this->assertStringContainsString('projects/tunesa-app/messages/0:99', $body);
    }

    public function test_attendance_alerts_push_to_the_employee_and_only_in_app_to_the_supervisor(): void
    {
        \Illuminate\Support\Facades\Storage::fake('private');
        $this->fakeGoogle();
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['display_name' => 'Lima'])]);
        $this->configurePlatform();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-09 06:00:00', 'America/Lima'));

        $supervisor = $this->makeUser($this->acme, 'supervisor', ['fcm_token' => 'supervisor-token']);
        $branch     = $this->makeBranch($this->acme, ['supervisor_id' => $supervisor->id]);
        $this->emp->branches()->attach($branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $shift = \App\Modules\HR\Models\Shift::create(['tenant_id' => $this->acme->id, 'name' => 'Día', 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_period_minutes' => 10, 'working_days' => [0, 1, 2, 3, 4, 5, 6]]);
        \App\Modules\HR\Models\EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'shift_id' => $shift->id,
            'branch_id' => $branch->id, 'effective_from' => '2020-01-01', 'is_active' => true]);
        app(TenantContext::class)->clear();

        $this->actingIn($this->emp, $this->acme)->post('/api/attendance/clock-in', [
            'latitude' => -12.09418, 'longitude' => -77.03560,
            'photo' => \Illuminate\Http\UploadedFile::fake()->image('s.jpg', 100, 100),
        ])->assertCreated()->assertJsonPath('punch.suspicion_reasons', ['OUTSIDE_SCHEDULE']);

        Http::assertSent(fn (Request $r) => $r->url() === self::SEND_URL
            && $r['message']['token'] === 'device-token-123'
            && $r['message']['data']['type'] === 'attendance.outside_schedule');
        Http::assertNotSent(fn (Request $r) => $r->url() === self::SEND_URL && $r['message']['token'] === 'supervisor-token');
        $this->assertSame(['attendance.outside_schedule'], $supervisor->notifications()->pluck('type')->all());
    }

    private function leaveRequest(): \App\Modules\HR\Models\LeaveRequest
    {
        return \App\Modules\HR\Models\LeaveRequest::withoutEvents(fn () => \App\Modules\HR\Models\LeaveRequest::create([
            'tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'type' => 'vacation',
            'start_date' => '2026-11-02', 'end_date' => '2026-11-06', 'days' => 5, 'reason' => 'Viaje', 'status' => 'approved',
        ]));
    }
}
