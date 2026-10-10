<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CompanyConfiguration;
use App\Models\EmployeeProfile;
use App\Models\TenantSetting;
use App\Presenters\AuditLogPresenter;
use App\Services\AuditLogger;
use App\Support\Labels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Bitácora estructurada: {changes: [{field, old, new}], context: {...}},
 * secretos enmascarados y presentación sin datos técnicos.
 */
class AuditStructuredLogTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const COLORS = ['primary_color' => '#1E40AF', 'secondary_color' => '#F59E0B'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_log_changes_stores_only_changed_fields_and_masks_secrets(): void
    {
        $acme = $this->makeTenant('acme');
        $row  = TenantSetting::create(['tenant_id' => $acme->id]);

        app(AuditLogger::class)->logChanges('tenant_settings.updated', $row, [
            'default_grace_minutes' => 5,
            'lunch_paid'            => false,
            'trade_name'            => 'Acme',
            'webhook_secret'        => null,
            'maps_api_key'          => 'AIzaOldKey0001',
            'fcm_service_account_json' => null,
            'updated_at'            => '2026-01-01',
        ], [
            'default_grace_minutes' => 10,
            'lunch_paid'            => true,
            'trade_name'            => 'Acme',
            'webhook_secret'        => 'whsec_supersecret9876',
            'maps_api_key'          => 'AIzaNewKey0002',
            'fcm_service_account_json' => '{"private_key":"-----BEGIN PRIVATE KEY-----abc"}',
            'updated_at'            => '2026-02-01',
        ], ['scope' => 'tenant', 'webhook_secret' => 'whsec_supersecret9876']);

        $log  = AuditLog::latest('id')->first();
        $json = json_encode($log->meta);
        $byField = collect($log->meta['changes'])->keyBy('field');

        $this->assertSame(['default_grace_minutes', 'lunch_paid', 'webhook_secret', 'maps_api_key', 'fcm_service_account_json'], $byField->keys()->all());
        $this->assertSame(['field' => 'default_grace_minutes', 'old' => 5, 'new' => 10], $byField['default_grace_minutes']);
        $this->assertSame('••••••••9876', $byField['webhook_secret']['new']);
        $this->assertNull($byField['webhook_secret']['old']);
        $this->assertSame('••••••••0001', $byField['maps_api_key']['old']);
        $this->assertSame('(cambiado)', $byField['fcm_service_account_json']['new']);
        $this->assertSame('tenant', $log->meta['context']['scope']);

        foreach (['whsec_', 'supersecret', 'AIzaNew', 'AIzaOld', 'PRIVATE KEY'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
    }

    public function test_model_update_helpers_capture_old_and_new_values(): void
    {
        $acme   = $this->makeTenant('acme');
        $branch = $this->makeBranch($acme, ['name' => 'Sede Centro', 'allowed_radius_meters' => 100]);
        $audit  = app(AuditLogger::class);

        // Antes de guardar: getDirty/getOriginal
        $branch->allowed_radius_meters = 250;
        $audit->logModelUpdate('branch.updated', $branch);
        $this->assertSame([['field' => 'allowed_radius_meters', 'old' => 100, 'new' => 250]], AuditLog::latest('id')->first()->meta['changes']);
        $branch->save();

        // Después de guardar: getChanges + snapshot previo
        $before = AuditLogger::snapshot($branch);
        $branch->update(['is_active' => false]);
        $audit->logModelUpdate('branch.updated', $branch, [], $before);
        $this->assertSame([['field' => 'is_active', 'old' => true, 'new' => false]], AuditLog::latest('id')->first()->meta['changes']);

        // updateWithAudit = fill + save + bitácora
        $audit->updateWithAudit('branch.updated', $branch, ['name' => 'Sede Norte']);
        $this->assertSame('Sede Norte', $branch->fresh()->name);
        $this->assertSame([['field' => 'name', 'old' => 'Sede Centro', 'new' => 'Sede Norte']], AuditLog::latest('id')->first()->meta['changes']);
    }

    public function test_company_settings_panel_logs_before_and_after(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin', ['first_name' => 'Carlos', 'last_name' => 'Ramírez']);
        CompanyConfiguration::create(['tenant_id' => $acme->id, 'geofence_policy' => 'reject', 'max_upload_mb' => 5, 'mobile_payslips_enabled' => true]);

        $this->actingAs($admin)->put('/settings/mobile', [
            'mobile_payslips_enabled' => '1', 'max_upload_mb' => 8, 'geofence_policy' => 'flag',
        ])->assertSessionHasNoErrors();

        $log     = AuditLog::where('action', 'company_configuration.updated')->latest('id')->first();
        $changes = collect($log->meta['changes'])->keyBy('field');
        $this->assertSame(['old' => 'reject', 'new' => 'flag'], collect($changes['geofence_policy'])->only('old', 'new')->all());
        $this->assertSame(5, $changes['max_upload_mb']['old']);
        $this->assertSame(8, (int) $changes['max_upload_mb']['new']);
        $this->assertArrayNotHasKey('mobile_payslips_enabled', $changes->all()); // no cambió

        $page = $this->get('/audit')->assertOk()
            ->assertSee('Configuración actualizada')
            ->assertSee('Carlos Ramírez cambió')
            ->assertSee('Ver cambios')
            ->assertSee('Política de geocerca')
            ->assertSee('Registrar como incidencia')
            ->assertSee('Rechazar')
            ->assertSee('8 MB')
            ->assertSee('Valor previo');
        $page->assertDontSee('geofence_policy')->assertDontSee('max_upload_mb')->assertDontSee('company_configuration.updated</', false);
    }

    public function test_tenant_settings_panel_shows_labels_inherit_and_masked_secrets(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin', ['first_name' => 'Carlos', 'last_name' => 'Ramírez']);
        TenantSetting::create(['tenant_id' => $acme->id, 'default_grace_minutes' => 5, 'lunch_paid' => false]);

        $this->actingAs($admin)->put('/settings/branding', self::COLORS + [
            'default_grace_minutes' => 15, 'lunch_paid' => '', 'webhook_secret' => 'whsec_1234567890', 'accent_color' => '#10B981',
        ])->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'tenant_settings.updated')->latest('id')->first();
        $this->assertStringNotContainsString('whsec_', json_encode($log->meta));
        $fields = array_column($log->meta['changes'], 'field');
        $this->assertContains('default_grace_minutes', $fields);
        $this->assertContains('lunch_paid', $fields);

        $presenter = AuditLogPresenter::make($log, $admin);
        $rows = collect($presenter->changes())->keyBy('field');
        $this->assertSame('Tolerancia por defecto (min)', $rows['default_grace_minutes']['label']);
        $this->assertSame('5 min', $rows['default_grace_minutes']['old']);
        $this->assertSame('15 min', $rows['default_grace_minutes']['new']);
        $this->assertSame('No', $rows['lunch_paid']['old']);
        $this->assertSame('Hereda de la plataforma', $rows['lunch_paid']['new']);
        $this->assertSame('••••••••7890', $rows['webhook_secret']['new']);
        $this->assertStringContainsString('#10B981', (string) $rows['accent_color']['new']);
        $this->assertStringContainsString('color-swatch', (string) $rows['accent_color']['new']);
        $this->assertMatchesRegularExpression('/^Carlos Ramírez cambió \d+ campos de Marca y app: .+ y \d+ más\.$/u', $presenter->summary());

        $this->get('/audit')->assertOk()
            ->assertSee('Marca y app actualizada')
            ->assertSee('Tolerancia por defecto (min)')
            ->assertSee('Hereda de la plataforma')
            ->assertDontSee('whsec_1234567890')
            ->assertDontSee('default_grace_minutes')
            ->assertDontSee('"changes"', false);
    }

    public function test_bank_account_and_password_changes_are_never_stored_in_clear(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $emp   = $this->makeUser($acme, 'employee');
        EmployeeProfile::create(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'bank_account_number' => '19112345678001']);

        $this->actingIn($admin, $acme)->putJson("/api/admin/employees/{$emp->id}/profile", [
            'bank_account_number' => '191-99887766-0-55',
        ])->assertOk();

        $log = AuditLog::where('action', 'profile.updated_by_hr')->latest('id')->first();
        $change = collect($log->meta['changes'])->firstWhere('field', 'bank_account_number');
        $this->assertSame('••••••••8001', $change['old']);
        $this->assertSame('••••••••6055', $change['new']);
        $this->assertStringNotContainsString('99887766', json_encode($log->meta));

        // Contraseña nueva: solo "(cambiado)", nunca el hash
        $branch = $this->makeBranch($acme);
        $this->actingAs($admin)->put("/employees/{$emp->id}", [
            'first_name' => 'Ana', 'last_name' => 'Pérez', 'email' => $emp->email, 'password' => 'NuevaClave123',
            'role' => 'employee', 'status' => 'active', 'document_type' => 'dni', 'document_number' => '12345678',
            'branch_ids' => [$branch->id], 'primary_branch_id' => $branch->id,
        ])->assertSessionHasNoErrors();

        $log  = AuditLog::where('action', 'employee.updated')->latest('id')->first();
        $pass = collect($log->meta['changes'])->firstWhere('field', 'password');
        $this->assertSame('(cambiado)', $pass['new']);
        $this->assertStringNotContainsString('$2y$', json_encode($log->meta));
        $this->assertStringNotContainsString('NuevaClave', json_encode($log->meta));
    }

    public function test_legacy_rows_without_changes_render_labeled_context(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        AuditLog::create(['tenant_id' => $acme->id, 'user_id' => $admin->id, 'action' => 'tenant_settings.updated',
            'meta' => ['scope' => 'tenant', 'changed' => ['default_grace_minutes', 'lunch_paid'], 'secrets_changed' => ['webhook_secret']]]);
        AuditLog::create(['tenant_id' => $acme->id, 'user_id' => $admin->id, 'action' => 'company_configuration.updated',
            'meta' => ['mobile_payslips_enabled' => true, 'geofence_policy' => 'flag']]);

        $this->actingAs($admin)->get('/audit')->assertOk()
            ->assertSee('Campos cambiados')
            ->assertSee('Tolerancia por defecto (min) y Almuerzo pagado')
            ->assertSee('Clave secreta del webhook')
            ->assertSee('App: boletas de pago')
            ->assertSee('Registrar como incidencia')
            ->assertDontSee('mobile_payslips_enabled')
            ->assertDontSee('secrets_changed')
            ->assertDontSee('default_grace_minutes');
    }

    public function test_ip_is_masked_for_other_roles_and_user_agent_is_summarized(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $hr    = $this->makeUser($acme, 'hr_manager');
        $log   = AuditLog::create(['tenant_id' => $acme->id, 'user_id' => $admin->id, 'action' => 'document.viewed',
            'ip' => '190.23.45.67', 'user_agent' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36']);

        $this->assertSame('190.23.45.67', AuditLogPresenter::make($log, $admin)->ip());
        $this->assertSame('190.23.x.x', AuditLogPresenter::make($log, $hr)->ip());
        $this->assertSame('190.23.x.x', AuditLogPresenter::make($log)->ip());
        $this->assertSame('Chrome en Android', AuditLogPresenter::make($log)->device());
        $this->assertSame('Safari en iPhone', Labels::userAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'));
        $this->assertSame('App móvil en Android', Labels::userAgent('okhttp/4.12.0 Android'));

        $this->actingAs($admin)->get('/audit')->assertOk()
            ->assertSee('Chrome en Android')->assertSee('190.23.45.67')
            ->assertDontSee('AppleWebKit');
    }

    public function test_branch_update_from_panel_is_logged_with_labels(): void
    {
        $acme   = $this->makeTenant('acme');
        $admin  = $this->makeUser($acme, 'company_admin', ['first_name' => 'Carlos', 'last_name' => 'Ramírez']);
        $branch = $this->makeBranch($acme, ['name' => 'Sede Centro', 'allowed_radius_meters' => 100]);

        $this->actingAs($admin)->put("/branches/{$branch->id}", [
            'name' => 'Sede Centro', 'address' => 'Av. Arequipa 123', 'latitude' => -12.09418, 'longitude' => -77.03560, 'allowed_radius_meters' => 300, 'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'branch.updated')->latest('id')->first();
        $this->assertNotNull($log);
        $rows = collect(AuditLogPresenter::make($log, $admin)->changes())->keyBy('field');
        $this->assertSame('Radio de la geocerca (m)', $rows['allowed_radius_meters']['label']);
        $this->assertSame('100 m', $rows['allowed_radius_meters']['old']);
        $this->assertSame('300 m', $rows['allowed_radius_meters']['new']);

        $this->get('/audit')->assertSee('Sucursal actualizada')->assertSee('Sucursal · Sede Centro')->assertDontSee('Branch #');
    }

    public function test_summary_contracts_del_and_context_has_no_technical_keys(): void
    {
        $acme   = $this->makeTenant('acme');
        $admin  = $this->makeUser($acme, 'company_admin', ['first_name' => 'Carlos', 'last_name' => 'Ramírez']);
        $this->actingAs($admin);

        $log = AuditLog::create(['tenant_id' => $acme->id, 'user_id' => $admin->id, 'action' => 'shift.updated',
            'meta' => ['changes' => [['field' => 'grace_period_minutes', 'old' => 10, 'new' => 12]]]]);
        $this->assertStringEndsWith('cambió 1 campo del turno: Tolerancia.', AuditLogPresenter::make($log, $admin)->summary());

        app(AuditLogger::class)->log('push.tested', $admin, ['sent' => false, 'response_ms' => 0, 'error' => 'Sin dispositivo.']);
        app(AuditLogger::class)->log('payslip.deleted', null, ['employee_id' => $admin->id, 'punch_id' => 9]);
        foreach (AuditLog::whereIn('action', ['push.tested', 'payslip.deleted'])->get() as $row) {
            foreach (AuditLogPresenter::make($row, $admin)->contextPairs() as $label => $value) {
                $this->assertDoesNotMatchRegularExpression('/_| id$|^Ok$|^Ms$/', $label);
            }
        }
        $pairs = AuditLogPresenter::make(AuditLog::where('action', 'payslip.deleted')->first(), $admin)->contextPairs();
        $this->assertSame('Carlos Ramírez', (string) $pairs['Colaborador']);
        $this->assertArrayNotHasKey('Registro de la bitácora de marcaciones', $pairs);
    }
}
