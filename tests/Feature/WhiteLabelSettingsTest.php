<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\CompanyConfiguration;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Shift;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Marca y app: tenant_settings, GET /api/v1/app/config, reglas de marcación
 * (selfie, sin conexión, rostro, versión mínima) y pantalla /settings/branding.
 */
class WhiteLabelSettingsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const APP_CONFIG_STRUCTURE = [
        'success',
        'data' => [
            'tenant' => ['slug', 'company_name', 'trade_name'],
            'branding' => ['logo_url', 'logo_dark_url', 'favicon_url', 'splash_url', 'primary_color', 'secondary_color', 'accent_color', 'navbar_color'],
            'mobile' => ['api_base_url', 'min_supported_version', 'latest_version', 'force_update', 'update_url', 'selfie_required',
                'face_detection_required', 'offline_mode_enabled', 'offline_max_hours', 'max_gps_accuracy_meters', 'geofence_strict_mode',
                'mock_location_policy', 'max_time_drift_seconds', 'geocoding_provider', 'fcm_enabled'],
            'rules' => ['grace_minutes', 'lunch_minutes', 'lunch_paid', 'default_geofence_radius'],
            'contact' => ['email', 'phone', 'website', 'address'],
            'modules' => ['payslips', 'documents', 'payment_info', 'uniforms', 'trainings', 'ethics'],
        ],
        'meta' => ['config_version'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('public');
        Storage::fake('private');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Fila de configuración de una empresa (o de la plataforma con null) */
    private function setRow(?Tenant $tenant, array $values): TenantSetting
    {
        $row = TenantSetting::editableRowFor($tenant);
        $row->fill($values)->save();

        return $row;
    }

    private function employeeWithBranch(Tenant $tenant): User
    {
        $emp = $this->makeUser($tenant);
        $emp->branches()->attach($this->makeBranch($tenant)->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);

        return $emp;
    }

    private function clockPayload(array $overrides = []): array
    {
        return array_merge([
            'latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12,
            'device_id' => 'test-device', 'device_os' => 'android',
            'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
        ], $overrides);
    }

    // =========================================================================
    // PANEL: /settings/branding
    // =========================================================================

    public function test_branding_page_renders_for_company_admin_and_super_admin(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $hr    = $this->makeUser($acme, 'hr_manager');
        $root  = $this->makeUser(null, 'super_admin');

        $this->actingAs($admin)->get('/settings/branding')->assertOk()
            ->assertSee('Marca y apariencia')->assertSee('App móvil')->assertSee('Reglas operativas')->assertSee('Integraciones')
            ->assertSee('trycloudflare.com')->assertSee('Rechazar la marcación')->assertDontSee('Valores por defecto de la plataforma.');
        $this->actingAs($admin)->get('/dashboard')->assertSee(route('settings.branding'));
        $this->actingAs($hr)->get('/settings/branding')->assertForbidden();

        $this->actingAs($root)->get('/settings/branding')->assertOk()->assertSee('Valores por defecto de la plataforma.');
        $this->actingAs($root)->post('/admin/switch-tenant', ['tenant_id' => $acme->id]);
        $this->get('/settings/branding')->assertOk()->assertSee('Acme')->assertDontSee('Valores por defecto de la plataforma.');
    }

    public function test_company_admin_uploads_logos_and_replacing_deletes_the_old_file(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');

        $this->actingAs($admin)->put('/settings/branding', [
            'trade_name'      => 'Acme Seguridad',
            'primary_color'   => '#0f766e',
            'secondary_color' => '#F59E0B',
            'accent_color'    => '#22C55E',
            'navbar_color'    => '#111827',
            'logo'            => UploadedFile::fake()->image('logo.png', 200, 60),
            'logo_dark'       => UploadedFile::fake()->image('logo-dark.png', 200, 60),
            'favicon'         => UploadedFile::fake()->image('favicon.png', 64, 64),
            'splash'          => UploadedFile::fake()->image('splash.jpg', 1080, 1920),
            'selfie_required' => '1',
            'tab'             => 'marca',
        ])->assertRedirect(route('settings.branding', ['tab' => 'marca']))->assertSessionHasNoErrors();

        $acme->refresh();
        $row = TenantSetting::rowFor($acme);
        $this->assertSame('#0F766E', $acme->primary_color);
        $this->assertSame('Acme Seguridad', $row->trade_name);
        $this->assertStringStartsWith("tenants/{$acme->id}/branding/", $acme->logo_path);
        foreach ([$acme->logo_path, $row->logo_dark_path, $row->favicon_path, $row->splash_path] as $path) {
            Storage::disk('public')->assertExists($path);
        }

        // Reemplazar el logo borra el anterior; "Quitar" elimina el splash
        $oldLogo = $acme->logo_path;
        $oldSplash = $row->splash_path;
        $this->actingAs($admin)->put('/settings/branding', [
            'primary_color' => '#0F766E', 'secondary_color' => '#F59E0B',
            'logo' => UploadedFile::fake()->image('logo2.webp', 200, 60), 'remove_splash' => '1',
        ])->assertSessionHasNoErrors();

        $acme->refresh();
        $this->assertNotSame($oldLogo, $acme->logo_path);
        Storage::disk('public')->assertMissing($oldLogo);
        Storage::disk('public')->assertExists($acme->logo_path);
        Storage::disk('public')->assertMissing($oldSplash);
        $this->assertNull(TenantSetting::rowFor($acme)->splash_path);

        // SVG no se acepta; favicon solo PNG/ICO
        $this->actingAs($admin)->put('/settings/branding', [
            'primary_color' => '#0F766E', 'secondary_color' => '#F59E0B',
            'logo' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
            'favicon' => UploadedFile::fake()->image('fav.jpg'),
        ])->assertSessionHasErrors(['logo', 'favicon']);

        // El panel usa la marca: título, color y logo
        $this->actingAs($admin)->get('/dashboard')->assertOk()
            ->assertSee('— Acme Seguridad', false)->assertSee('--brand: #0F766E', false)
            ->assertSee('--brand-navbar: #111827', false)->assertSee($acme->logo_path);

        $this->assertTrue(AuditLog::where('action', 'tenant_settings.updated')->where('tenant_id', $acme->id)->exists());
    }

    public function test_settings_validation_and_geofence_policy_is_written_to_company_configuration(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $base  = ['primary_color' => '#1E40AF', 'secondary_color' => '#F59E0B'];

        $this->actingAs($admin)->put('/settings/branding', $base + ['api_base_url' => 'http://inseguro.test'])->assertSessionHasErrors('api_base_url');
        $this->actingAs($admin)->put('/settings/branding', $base + ['api_base_url' => 'https://xx.trycloudflare.com/api'])->assertSessionHasErrors('api_base_url');
        $this->actingAs($admin)->put('/settings/branding', $base + ['offline_max_hours' => 100])->assertSessionHasErrors('offline_max_hours');
        $this->actingAs($admin)->put('/settings/branding', $base + ['min_supported_version' => 'uno'])->assertSessionHasErrors('min_supported_version');
        $this->actingAs($admin)->put('/settings/branding', $base + ['navbar_color' => 'rojo'])->assertSessionHasErrors('navbar_color');

        $this->actingAs($admin)->put('/settings/branding', $base + [
            'api_base_url' => 'https://abcd.trycloudflare.com/', 'geofence_policy' => 'flag',
            'offline_mode_enabled' => '1', 'offline_max_hours' => 12, 'default_geofence_radius' => 80, 'default_grace_minutes' => 7,
        ])->assertSessionHasNoErrors();

        $this->assertSame('flag', CompanyConfiguration::forTenantId($acme->id)->geofence_policy);
        $effective = TenantSetting::forTenant($acme->fresh());
        $this->assertSame('https://abcd.trycloudflare.com', $effective->api_base_url);
        $this->assertFalse($effective->geofenceStrictMode());
        $this->assertTrue($effective->selfie_required); // empresa: ausente = no cambiar (hereda true de fábrica)
        $this->assertNull(TenantSetting::rowFor($acme)->selfie_required);

        // Los formularios de alta proponen los valores por defecto
        $this->actingAs($admin)->get('/branches/create')->assertOk()->assertSee('value="80"', false);
        $this->actingAs($admin)->get('/shifts/create')->assertOk()
            ->assertInertia(fn ($page) => $page->component('HR/Pages/Shifts/Form')->where('shift.grace_period_minutes', 7));
    }

    public function test_company_admin_only_edits_its_own_tenant(): void
    {
        $acme   = $this->makeTenant('acme');
        $globex = $this->makeTenant('globex', ['primary_color' => '#123456']);
        $admin  = $this->makeUser($acme, 'company_admin');
        $this->setRow($globex, ['trade_name' => 'Globex SAC', 'offline_max_hours' => 5]);

        $this->actingAs($admin)->put('/settings/branding', [
            'tenant_id' => $globex->id, 'trade_name' => 'Hackeado', 'primary_color' => '#000000',
            'secondary_color' => '#000000', 'offline_max_hours' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Hackeado', TenantSetting::rowFor($acme)->trade_name);
        $this->assertSame('Globex SAC', TenantSetting::rowFor($globex)->trade_name);
        $emp = $this->makeUser($acme);
        $this->assertSame(5, TenantSetting::rowFor($globex)->offline_max_hours);
        $this->assertSame('#123456', $globex->fresh()->primary_color);
        $this->assertNull(TenantSetting::rowFor(null));

        // API: también ignora tenant_id y queda en la empresa del token
        $this->actingIn($admin, $acme)->putJson('/api/admin/app-config', ['tenant_id' => $globex->id, 'trade_name' => 'Por API', 'lunch_minutes' => 45])
            ->assertOk()->assertJsonPath('data.settings.trade_name', 'Por API')->assertJsonPath('data.effective.rules.lunch_minutes', 45);
        $this->assertSame('Globex SAC', TenantSetting::rowFor($globex)->trade_name);

        // El token de Acme no sirve contra Globex
        $this->actingIn($admin, $acme)->withHeader('X-Tenant-Slug', 'globex')->getJson('/api/admin/app-config')->assertStatus(403);

        // Un colaborador no puede usar el endpoint de administración
        $this->actingIn($emp, $acme)->getJson('/api/admin/app-config')->assertForbidden();
    }

    public function test_super_admin_global_view_edits_platform_defaults_inherited_by_tenants(): void
    {
        $root = $this->makeUser(null, 'super_admin');
        $acme = $this->makeTenant('acme');

        $this->actingAs($root)->put('/settings/branding', [
            'trade_name' => 'Plataforma RRHH', 'primary_color' => '#7C3AED', 'offline_mode_enabled' => '1',
            'offline_max_hours' => 12, 'selfie_required' => '1', 'api_base_url' => 'https://api.tunesa.app',
            'logo' => UploadedFile::fake()->image('platform.png', 120, 40),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $global = TenantSetting::rowFor(null);
        $this->assertNotNull($global);
        $this->assertNull($global->tenant_id);
        $this->assertSame('Plataforma RRHH', $global->trade_name);
        $this->assertStringStartsWith('platform/branding/', $global->logo_path);
        // Acme solo tiene su secreto de webhook (generado al crearse): todo lo demás lo hereda
        $this->assertSame(0, TenantSetting::whereNotNull('tenant_id')->whereNotNull('trade_name')->count());

        // Acme hereda de la plataforma (pero conserva su nombre y su color)
        $this->getJson('/api/v1/app/config?tenant_slug=acme')->assertOk()
            ->assertJsonPath('data.tenant.trade_name', 'Acme')
            ->assertJsonPath('data.mobile.offline_mode_enabled', true)
            ->assertJsonPath('data.mobile.offline_max_hours', 12)
            ->assertJsonPath('data.mobile.api_base_url', 'https://api.tunesa.app')
            ->assertJsonPath('data.branding.primary_color', '#1E40AF')
            ->assertJsonPath('data.branding.logo_url', asset('storage/' . $global->logo_path));

        // Sin empresa: los valores de la plataforma
        $this->getJson('/api/v1/app/config')->assertOk()
            ->assertJsonPath('data.tenant', null)->assertJsonPath('data.branding.primary_color', '#7C3AED');

        // La fila de la empresa pisa a la global
        $this->setRow($acme, ['offline_max_hours' => 3]);
        $this->getJson('/api/v1/app/config?tenant_slug=acme')->assertJsonPath('data.mobile.offline_max_hours', 3);

        // El login de la plataforma usa la marca global
        auth()->logout();
        $this->get('/login')->assertOk()->assertSee('Plataforma RRHH')->assertSee('--brand: #7C3AED', false);
    }

    public function test_login_page_uses_the_brand_of_the_tenant_domain(): void
    {
        $acme = $this->makeTenant('acme', ['custom_domain' => 'asistencia.acme.pe', 'primary_color' => '#112233']);
        $this->setRow($acme, ['trade_name' => 'Acme Asistencia']);

        $this->get('http://asistencia.acme.pe/login')->assertOk()
            ->assertSee('Acme Asistencia')->assertSee('--brand: #112233', false);
        $this->get('http://localhost/login')->assertOk()->assertDontSee('Acme Asistencia')->assertSee('--brand: #1E40AF', false);
    }

    // =========================================================================
    // API PÚBLICA: /api/v1/app/config
    // =========================================================================

    public function test_app_config_returns_exact_structure_for_tenant_and_platform(): void
    {
        $acme = $this->makeTenant('acme', ['phone' => '014445555']);
        $this->setRow($acme, ['accent_color' => '#22c55e', 'webhook_secret' => 'whsec_super_secreto', 'maps_api_key' => 'AIzaClaveMapas']);

        $this->setRow($this->makeTenant('navy'), ['navbar_color' => '#0b1f3a']);
        $this->getJson('/api/v1/app/config?tenant_slug=navy')->assertOk()->assertJsonPath('data.branding.navbar_color', '#0B1F3A');

        $res = $this->getJson('/api/v1/app/config?tenant_slug=acme')->assertOk()
            ->assertJsonStructure(self::APP_CONFIG_STRUCTURE)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tenant.slug', 'acme')
            ->assertJsonPath('data.branding.accent_color', '#22C55E')
            // Sin color de barra elegido la app usa su barra clara
            ->assertJsonPath('data.branding.navbar_color', null)
            ->assertJsonPath('data.mobile.min_supported_version', '1.0.0')
            ->assertJsonPath('data.mobile.selfie_required', true)
            ->assertJsonPath('data.mobile.geofence_strict_mode', true)
            ->assertJsonPath('data.rules.grace_minutes', 10)
            ->assertJsonPath('data.contact.email', 'acme@example.test')
            ->assertJsonPath('data.contact.phone', '014445555');

        // Exactamente estas secciones, sin datos de integraciones
        $this->assertSame(['tenant', 'branding', 'mobile', 'rules', 'contact', 'modules'], array_keys($res->json('data')));
        $this->assertSame(['config_version'], array_keys($res->json('meta')));
        $this->assertCount(15, $res->json('data.mobile'));
        $res->assertDontSee('whsec_super_secreto')->assertDontSee('AIzaClaveMapas')->assertDontSee('webhook');
        $this->assertNotFalse(Carbon::parse($res->json('meta.config_version')));

        // Alias sin /v1 y con la cabecera de la app
        $this->withHeader('X-Tenant-Slug', 'acme')->getJson('/api/app/config')->assertOk()->assertJsonPath('data.tenant.slug', 'acme');

        // Sin empresa → plataforma
        $this->flushHeaders();
        $platform = $this->getJson('/api/v1/app/config')->assertOk()->assertJsonPath('data.tenant', null);
        $structure = self::APP_CONFIG_STRUCTURE;
        unset($structure['data']['tenant']);
        $platform->assertJsonStructure($structure);
        $platform->assertJsonPath('data.branding.navbar_color', null);
        foreach (['primary_color', 'secondary_color', 'accent_color'] as $color) {
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $platform->json("data.branding.{$color}"));
        }

        // Desconocida o suspendida → 404
        $this->getJson('/api/v1/app/config?tenant_slug=no-existe')->assertNotFound()
            ->assertJson(['success' => false, 'code' => 'TENANT_NOT_FOUND']);
        $this->makeTenant('suspendida', ['status' => 'suspended']);
        $this->getJson('/api/app/config?tenant_slug=suspendida')->assertNotFound()->assertJsonPath('code', 'TENANT_NOT_FOUND');

        // /api/tenant/branding mantiene sus claves y suma las nuevas
        $this->flushHeaders()->withHeader('X-Tenant-Slug', 'acme')->getJson('/api/tenant/branding')->assertOk()
            ->assertJsonStructure(['data' => ['company_name', 'slug', 'logo_url', 'primary_color', 'secondary_color', 'custom_domain',
                'timezone', 'currency', 'trade_name', 'logo_dark_url', 'favicon_url', 'splash_url', 'accent_color', 'navbar_color', 'contact']])
            ->assertDontSee('whsec_super_secreto');
    }

    public function test_secrets_are_encrypted_at_rest_and_masked_for_admins(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');

        $this->actingAs($admin)->put('/settings/branding', [
            'primary_color' => '#1E40AF', 'secondary_color' => '#F59E0B',
            'webhook_url' => 'https://erp.acme.pe/hook', 'webhook_secret' => 'whsec_1234567890', 'maps_api_key' => 'AIzaSyClave9876',
        ])->assertSessionHasNoErrors();

        $raw = DB::table('tenant_settings')->where('tenant_id', $acme->id)->first();
        $this->assertNotSame('whsec_1234567890', $raw->webhook_secret);
        $this->assertStringNotContainsString('whsec_1234567890', $raw->webhook_secret);
        $this->assertNotSame('AIzaSyClave9876', $raw->maps_api_key);
        $this->assertSame('whsec_1234567890', TenantSetting::rowFor($acme)->webhook_secret);

        // Vacío = no cambiar
        $this->actingAs($admin)->put('/settings/branding', ['primary_color' => '#1E40AF', 'secondary_color' => '#F59E0B', 'webhook_secret' => ''])
            ->assertSessionHasNoErrors();
        $this->assertSame('whsec_1234567890', TenantSetting::rowFor($acme)->webhook_secret);

        // Panel: precargado en un campo de contraseña (oculto hasta "Mostrar"); API admin: enmascarado
        $page = $this->actingAs($admin)->get('/settings/integrations')->assertOk();
        $this->assertSame('whsec_1234567890', $page->prop('values.webhook_secret'));
        $this->assertSame(1, substr_count($page->getContent(), 'whsec_1234567890'));
        $this->assertStringNotContainsString('AIzaSyClave9876', $page->getContent());
        $this->actingIn($admin, $acme)->getJson('/api/admin/app-config')->assertOk()
            ->assertJsonPath('data.settings.has_webhook_secret', true)
            ->assertJsonPath('data.settings.webhook_secret', '••••••••7890')
            ->assertDontSee('whsec_1234567890')->assertDontSee('AIzaSyClave9876');

        // La bitácora no guarda secretos
        $audit = AuditLog::where('action', 'tenant_settings.updated')->latest('id')->first();
        $this->assertStringNotContainsString('whsec_', json_encode($audit->meta));

        // "Borrar" el secreto genera uno nuevo: el webhook nunca queda sin firma
        $this->actingAs($admin)->put('/settings/branding', ['primary_color' => '#1E40AF', 'secondary_color' => '#F59E0B', 'clear_webhook_secret' => '1']);
        $this->assertStringStartsWith('whsec_', TenantSetting::rowFor($acme)->webhook_secret);
        $this->assertNotSame('whsec_1234567890', TenantSetting::rowFor($acme)->webhook_secret);
    }

    // =========================================================================
    // MARCACIÓN
    // =========================================================================

    public function test_selfie_is_optional_only_when_the_company_disables_it(): void
    {
        $acme = $this->makeTenant('acme');
        $emp  = $this->employeeWithBranch($acme);

        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->clockPayload(['photo' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('photo');

        $this->setRow($acme, ['selfie_required' => false]);
        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->clockPayload(['photo' => null]))->assertCreated();
        $this->assertNull(Attendance::first()->clock_in_photo);

        $this->actingIn($emp, $acme)->post('/api/attendance/clock-out', $this->clockPayload(['photo' => null]))->assertOk();
    }

    public function test_gps_accuracy_and_face_detection_follow_company_settings(): void
    {
        $acme = $this->makeTenant('acme');
        $emp  = $this->employeeWithBranch($acme);
        $this->setRow($acme, ['max_gps_accuracy_meters' => 30, 'face_detection_required' => true]);

        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->clockPayload(['accuracy_meters' => 50]))
            ->assertStatus(422)->assertJsonPath('code', 'GPS_ACCURACY_TOO_LOW');
        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->clockPayload(['face_detected' => 0]))
            ->assertStatus(422)->assertJsonPath('code', 'FACE_NOT_DETECTED');
        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->clockPayload(['face_detected' => 1]))->assertCreated();
        $this->assertTrue(Attendance::first()->clock_in_meta['face_detected']);
    }

    public function test_offline_clock_in_rules_and_effective_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-09 12:00:00', 'America/Lima'));

        $acme = $this->makeTenant('acme');
        $emp  = $this->employeeWithBranch($acme);
        $shift = Shift::create(['tenant_id' => $acme->id, 'name' => 'Mañana', 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_period_minutes' => 10, 'working_days' => [0, 1, 2, 3, 4, 5, 6]]);
        EmployeeShift::create(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'shift_id' => $shift->id,
            'branch_id' => $emp->branches()->first()->id, 'effective_from' => '2026-01-01', 'is_active' => true]);

        // La empresa no permite marcar sin conexión
        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->clockPayload(['offline_recorded_at' => '2026-10-09T08:05:00-05:00']))
            ->assertStatus(422)->assertJsonPath('code', 'OFFLINE_NOT_ALLOWED');

        $this->setRow($acme, ['offline_mode_enabled' => true, 'offline_max_hours' => 6]);

        // Más antigua que offline_max_hours / en el futuro
        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->clockPayload(['offline_recorded_at' => '2026-10-09T05:30:00-05:00']))
            ->assertStatus(422)->assertJsonPath('code', 'OFFLINE_TOO_OLD');
        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->clockPayload(['offline_recorded_at' => '2026-10-09T13:00:00-05:00']))
            ->assertStatus(422)->assertJsonValidationErrors('offline_recorded_at');
        $this->assertSame(0, Attendance::count());

        // Aceptada: hora real (UTC convertida a Lima), puntual aunque llegue a las 12:00
        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->clockPayload(['offline_recorded_at' => '2026-10-09T13:05:00Z']))
            ->assertCreated();

        $att = Attendance::firstOrFail();
        $this->assertSame('2026-10-09 08:05:00', $att->clock_in_at->setTimezone('America/Lima')->format('Y-m-d H:i:s'));
        $this->assertSame(0, $att->late_minutes);
        $this->assertSame('present', $att->status);
        $this->assertTrue($att->clock_in_meta['offline']);
        $this->assertNotEmpty($att->clock_in_meta['synced_at']);
        $this->assertTrue(AuditLog::where('action', 'attendance.offline_synced')->where('subject_id', $att->id)->exists());

        // Salida sin conexión anterior a la entrada → rechazada; válida → horas con la hora real
        $this->actingIn($emp, $acme)->post('/api/attendance/clock-out', $this->clockPayload(['offline_recorded_at' => '2026-10-09T08:00:00-05:00']))
            ->assertStatus(422)->assertJsonPath('code', 'OFFLINE_BEFORE_CLOCK_IN');
        $this->actingIn($emp, $acme)->post('/api/attendance/clock-out', $this->clockPayload(['offline_recorded_at' => '2026-10-09T11:05:00-05:00']))
            ->assertOk();
        $att->refresh();
        $this->assertEquals(3.0, (float) $att->worked_hours);
        $this->assertTrue($att->clock_out_meta['offline']);

        // El panel muestra la insignia
        $hr = $this->makeUser($acme, 'hr_manager');
        $this->actingAs($hr)->get('/attendance?start_date=2026-10-09&end_date=2026-10-09')->assertOk()->assertSee('Sin conexión');
        $this->actingAs($hr)->get("/attendance/{$att->id}")->assertOk()->assertSee('Sin conexión');
    }

    public function test_offline_clock_in_late_minutes_use_the_recorded_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-09 09:00:00', 'America/Lima'));

        $acme = $this->makeTenant('acme');
        $emp  = $this->employeeWithBranch($acme);
        $shift = Shift::create(['tenant_id' => $acme->id, 'name' => 'Mañana', 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_period_minutes' => 10, 'working_days' => [0, 1, 2, 3, 4, 5, 6]]);
        EmployeeShift::create(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'shift_id' => $shift->id,
            'branch_id' => $emp->branches()->first()->id, 'effective_from' => '2026-01-01', 'is_active' => true]);
        $this->setRow(null, ['offline_mode_enabled' => true]); // heredado de la plataforma

        $this->actingIn($emp, $acme)->post('/api/attendance/clock-in', $this->clockPayload(['offline_recorded_at' => '2026-10-09T08:25:00-05:00']))
            ->assertCreated();
        $this->assertSame(25, Attendance::firstOrFail()->late_minutes);
    }

    // =========================================================================
    // VERSIÓN MÍNIMA (426)
    // =========================================================================

    public function test_outdated_app_gets_426_only_when_update_is_forced(): void
    {
        $acme = $this->makeTenant('acme');
        $emp  = $this->makeUser($acme);
        $this->setRow($acme, ['min_supported_version' => '2.1.0', 'latest_version' => '2.3.0', 'force_update' => true]);

        $this->actingIn($emp, $acme)->withHeader('X-App-Version', '2.0.9+45')->getJson('/api/attendance/today')
            ->assertStatus(426)
            ->assertJson(['success' => false, 'code' => 'APP_UPDATE_REQUIRED', 'data' => ['min_supported_version' => '2.1.0', 'latest_version' => '2.3.0']]);

        $this->actingIn($emp, $acme)->withHeader('X-App-Version', '2.1.0+7')->getJson('/api/attendance/today')->assertOk();
        $this->actingIn($emp, $acme)->getJson('/api/attendance/today')->assertOk(); // sin cabecera (panel, integraciones)

        // Login, logout y la configuración de arranque no se bloquean
        $this->withHeaders(['X-Tenant-Slug' => 'acme', 'X-App-Version' => '1.0.0'])
            ->postJson('/api/auth/login', ['email' => $emp->email, 'password' => 'Secret123'])->assertOk();
        $this->withHeader('X-App-Version', '1.0.0')->getJson('/api/v1/app/config?tenant_slug=acme')->assertOk();
        $this->actingIn($emp, $acme)->withHeader('X-App-Version', '1.0.0')->postJson('/api/auth/logout')->assertOk();

        // Sin force_update solo se informa (no bloquea)
        TenantSetting::rowFor($acme)->update(['force_update' => false]);
        $this->actingIn($emp, $acme)->withHeader('X-App-Version', '1.0.0')->getJson('/api/attendance/today')->assertOk();
    }
}
