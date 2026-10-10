<?php

namespace Tests\Feature;

use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Herencia de "Marca y app": Sí/No de tres estados en la empresa (NULL =
 * heredar), fila única de la plataforma y PUT /api/admin/branding delegado
 * en TenantSettingsService.
 */
class SettingsInheritanceTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const COLORS = ['primary_color' => '#1E40AF', 'secondary_color' => '#F59E0B'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('public');
    }

    private function setRow(?Tenant $tenant, array $values): TenantSetting
    {
        $row = TenantSetting::editableRowFor($tenant);
        $row->fill($values)->save();

        return $row;
    }

    public function test_company_booleans_are_tri_state_and_inherit_again_when_set_to_inherit(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $this->setRow(null, ['offline_mode_enabled' => true, 'lunch_paid' => true]);

        // El panel muestra el selector con el valor actual de la plataforma
        $this->actingAs($admin)->get('/settings/branding?tab=app')->assertOk()
            ->assertSee('Heredar de la plataforma (actual: Sí)')
            ->assertSee('Heredar de la plataforma (actual: No)')
            ->assertDontSee('role="switch" name="offline_mode_enabled"', false);

        // Sí / No explícitos quedan fijados
        $this->actingAs($admin)->put('/settings/branding', self::COLORS + [
            'offline_mode_enabled' => '0', 'selfie_required' => '0', 'force_update' => '1', 'lunch_paid' => '',
        ])->assertSessionHasNoErrors();

        $row = TenantSetting::rowFor($acme);
        $this->assertFalse($row->offline_mode_enabled);
        $this->assertFalse($row->selfie_required);
        $this->assertTrue($row->force_update);
        $this->assertNull($row->lunch_paid);
        $this->assertTrue(TenantSetting::forTenant($acme)->lunch_paid); // heredado

        // "Heredar" vuelve a NULL y sigue a la plataforma aunque cambie después
        $this->actingAs($admin)->put('/settings/branding', self::COLORS + [
            'offline_mode_enabled' => '', 'selfie_required' => '', 'force_update' => '', 'face_detection_required' => '',
        ])->assertSessionHasNoErrors();

        $row->refresh();
        foreach (['offline_mode_enabled', 'selfie_required', 'force_update', 'face_detection_required', 'lunch_paid'] as $flag) {
            $this->assertNull($row->{$flag}, $flag);
        }
        $this->assertTrue(TenantSetting::forTenant($acme)->offline_mode_enabled);

        $this->setRow(null, ['offline_mode_enabled' => false]);
        $this->assertFalse(TenantSetting::forTenant($acme)->offline_mode_enabled);

        // El selector marca la opción guardada
        $this->actingAs($admin)->put('/settings/branding', self::COLORS + ['face_detection_required' => '1']);
        $this->actingAs($admin)->get('/settings/branding?tab=app')->assertOk()
            ->assertSee('<option value="1" selected>Sí</option>', false);

        // Un valor que no es Sí/No/Heredar se rechaza
        $this->actingAs($admin)->put('/settings/branding', self::COLORS + ['selfie_required' => 'quizas'])
            ->assertSessionHasErrors('selfie_required');
    }

    public function test_numeric_fields_inherit_when_empty_and_show_the_inherited_placeholder(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $this->setRow(null, ['lunch_minutes' => 45, 'offline_max_hours' => 12]);
        $this->setRow($acme, ['lunch_minutes' => 30]);

        $this->actingAs($admin)->put('/settings/branding', self::COLORS + ['lunch_minutes' => '', 'offline_max_hours' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull(TenantSetting::rowFor($acme)->lunch_minutes);
        $this->assertSame(45, TenantSetting::forTenant($acme)->lunch_minutes);

        $this->actingAs($admin)->get('/settings/branding?tab=reglas')->assertOk()
            ->assertSee('name="lunch_minutes" min="0" max="480" value="" placeholder="45"', false)
            ->assertSee('placeholder="12"', false);
    }

    public function test_platform_row_keeps_plain_switches_where_unchecked_means_false(): void
    {
        $root = $this->makeUser(null, 'super_admin');
        $this->setRow(null, ['selfie_required' => true]);

        $this->actingAs($root)->get('/settings/branding?tab=app')->assertOk()
            ->assertSee('role="switch" name="selfie_required"', false)
            ->assertDontSee('Heredar de la plataforma');

        $this->actingAs($root)->put('/settings/branding', ['offline_mode_enabled' => '1'])->assertSessionHasNoErrors();

        $global = TenantSetting::rowFor(null);
        $this->assertFalse($global->selfie_required);
        $this->assertTrue($global->offline_mode_enabled);
    }

    public function test_admin_api_accepts_null_to_inherit_booleans(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $this->setRow(null, ['offline_mode_enabled' => true]);
        $this->setRow($acme, ['offline_mode_enabled' => false, 'lunch_paid' => true]);

        $this->actingIn($admin, $acme)->putJson('/api/admin/app-config', ['offline_mode_enabled' => null, 'lunch_paid' => false])
            ->assertOk()
            ->assertJsonPath('data.settings.offline_mode_enabled', null)
            ->assertJsonPath('data.settings.lunch_paid', false)
            ->assertJsonPath('data.effective.mobile.offline_mode_enabled', true);

        // Multipart: cadena vacía también es heredar
        $this->actingIn($admin, $acme)->post('/api/admin/app-config', ['lunch_paid' => ''])->assertOk()
            ->assertJsonPath('data.settings.lunch_paid', null);
    }

    public function test_platform_row_is_unique(): void
    {
        $first  = TenantSetting::platform();
        $second = TenantSetting::platform();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, TenantSetting::whereNull('tenant_id')->count());

        // editableRowFor(null) y el panel global usan la misma fila
        $this->assertSame($first->id, TenantSetting::editableRowFor(null)->id);
        $root = $this->makeUser(null, 'super_admin');
        $this->actingAs($root)->put('/settings/branding', ['trade_name' => 'Plataforma'])->assertSessionHasNoErrors();
        $this->actingAs($root)->put('/settings/branding', ['trade_name' => 'Plataforma 2'])->assertSessionHasNoErrors();
        $this->assertSame(1, TenantSetting::whereNull('tenant_id')->count());
        $this->assertSame('Plataforma 2', TenantSetting::rowFor(null)->trade_name);
    }

    public function test_legacy_branding_api_delegates_to_the_settings_service(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');

        // SVG rechazado (puede llevar scripts)
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->actingIn($admin, $acme)->post('/api/admin/branding', ['_method' => 'PUT', 'logo' => $svg])
            ->assertStatus(422)->assertJsonValidationErrors('logo');

        $this->actingIn($admin, $acme)->post('/api/admin/branding', [
            '_method' => 'PUT', 'company_name' => 'Acme Perú', 'primary_color' => '#abcdef',
            'logo' => UploadedFile::fake()->image('logo.png', 100, 40),
        ])->assertOk()
            ->assertJsonStructure(['success', 'message', 'data' => ['company_name', 'slug', 'logo_url', 'primary_color', 'secondary_color', 'custom_domain', 'timezone', 'currency']])
            ->assertJsonPath('data.company_name', 'Acme Perú')
            ->assertJsonPath('data.primary_color', '#ABCDEF');

        $first = $acme->fresh()->logo_path;
        Storage::disk('public')->assertExists($first);

        // Reemplazar el logo borra el anterior
        $this->actingIn($admin, $acme)->post('/api/admin/branding', ['_method' => 'PUT', 'logo' => UploadedFile::fake()->image('nuevo.png', 100, 40)])
            ->assertOk();
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($acme->fresh()->logo_path);

        // Queda en la bitácora como cualquier cambio de Marca y app
        $audit = AuditLog::where('action', 'tenant_settings.updated')->orderBy('id')->first();
        $this->assertNotNull($audit);
        $fields = array_column($audit->meta['changes'], 'field');
        $this->assertContains('company_name', $fields);
        $this->assertContains('logo_path', $fields);
    }
}
