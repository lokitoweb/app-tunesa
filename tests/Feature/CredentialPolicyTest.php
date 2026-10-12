<?php

namespace Tests\Feature;

use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\CredentialProvisioner;
use App\Modules\Core\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Credenciales administradas por RRHH: correo [DNI]@dominio, contraseña temporal con vencimiento
 * y restablecimiento solo desde el panel.
 */
class CredentialPolicyTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function login(string $email, string $password)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['X-Tenant-Slug' => 'acme', 'Accept' => 'application/json'])
            ->postJson('/api/auth/login', ['email' => $email, 'password' => $password, 'device_name' => 'pixel']);
    }

    public function test_email_uses_the_company_domain_and_avoids_collisions(): void
    {
        $acme = $this->makeTenant('acme');
        $svc  = app(CredentialProvisioner::class);

        $this->assertSame('70123456@turismonegreiros.com', $svc->emailFor($acme, ' 70.123.456 '));

        TenantSetting::editableRowFor($acme)->fill(['login_email_domain' => '@Acme.PE'])->save();
        $this->assertSame('70123456@acme.pe', $svc->emailFor($acme->fresh(), '70123456'));

        $this->makeUser($acme, 'employee', ['email' => '70123456@acme.pe']);
        $this->assertSame('70123456-2@acme.pe', $svc->emailFor($acme->fresh(), '70123456'));
    }

    public function test_temporary_password_is_random_and_unambiguous(): void
    {
        $svc = app(CredentialProvisioner::class);
        $a = $svc->temporaryPassword();

        $this->assertSame(CredentialProvisioner::TEMP_LENGTH, strlen($a));
        $this->assertNotSame($a, $svc->temporaryPassword());
        $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $a);
        $this->assertMatchesRegularExpression('/[A-Z]/', $a);
        $this->assertMatchesRegularExpression('/[a-z]/', $a);
        $this->assertMatchesRegularExpression('/\d/', $a);
    }

    public function test_panel_creates_employee_with_generated_credentials_shown_once(): void
    {
        $acme   = $this->makeTenant('acme');
        $branch = $this->makeBranch($acme);
        $admin  = $this->makeUser($acme, 'company_admin');

        $this->actingAs($admin)->post('/employees', [
            'first_name' => 'Rosa', 'last_name' => 'Quispe', 'role' => 'employee',
            'document_type' => 'dni', 'document_number' => '70123456',
            'branch_ids' => [$branch->id], 'primary_branch_id' => $branch->id,
        ])->assertSessionHasNoErrors()->assertSessionHas('credentials');

        $creds = session('credentials');
        $this->assertSame('70123456@turismonegreiros.com', $creds['email']);

        $rosa = User::withoutGlobalScopes()->where('email', $creds['email'])->firstOrFail();
        $this->assertTrue($rosa->must_change_password);
        $this->assertTrue(Hash::check($creds['password'], $rosa->password));
        $this->assertTrue($rosa->temp_password_expires_at->between(now()->addHours(71), now()->addHours(73)));

        // Se muestra una sola vez: en la página siguiente a la creación y nunca más
        $this->actingAs($admin)->get('/employees')->assertSee($creds['password']);
        $this->actingAs($admin)->get('/employees')->assertDontSee($creds['password']);
    }

    public function test_reset_access_issues_a_new_temporary_password_and_closes_sessions(): void
    {
        $acme = $this->makeTenant('acme');
        $hr   = $this->makeUser($acme, 'hr_manager');
        $ana  = $this->makeUser($acme, 'employee', ['email' => 'ana@acme.pe', 'failed_login_attempts' => 4]);
        $ana->createToken('app');

        $this->actingAs($hr)->post("/employees/{$ana->id}/reset-access")->assertRedirect()->assertSessionHas('credentials');
        $temp = session('credentials')['password'];

        $ana->refresh();
        $this->assertSame(0, $ana->tokens()->count());
        $this->assertTrue($ana->must_change_password);
        $this->assertSame(0, $ana->failed_login_attempts);
        $this->assertFalse(Hash::check('Secret123', $ana->password));

        app(TenantContext::class)->clear();
        $this->login('ana@acme.pe', $temp)->assertOk()->assertJsonPath('data.must_change_password', true);
    }

    public function test_reset_access_respects_rank_and_self(): void
    {
        $acme  = $this->makeTenant('acme');
        $hr    = $this->makeUser($acme, 'hr_manager');
        $admin = $this->makeUser($acme, 'company_admin');
        $sup   = $this->makeUser($acme, 'supervisor');
        $ana   = $this->makeUser($acme, 'employee');

        $this->actingAs($hr)->post("/employees/{$admin->id}/reset-access")->assertForbidden();
        $this->actingAs($hr)->post("/employees/{$hr->id}/reset-access")->assertForbidden();
        $this->actingAs($sup)->post("/employees/{$ana->id}/reset-access")->assertForbidden();
        $this->assertTrue(Hash::check('Secret123', $admin->fresh()->password));
    }

    public function test_expired_temporary_password_is_rejected_until_hr_resets(): void
    {
        $acme = $this->makeTenant('acme');
        $this->makeUser($acme, 'employee', [
            'email' => 'ana@acme.pe', 'must_change_password' => true, 'temp_password_expires_at' => now()->subMinute(),
        ]);
        app(TenantContext::class)->clear();

        $this->login('ana@acme.pe', 'Secret123')->assertForbidden()->assertJsonFragment(['Tu contraseña temporal venció. Pide a RRHH que restablezca tu acceso.']);
        $this->login('ana@acme.pe', 'Incorrecta1')->assertStatus(422);
    }

    public function test_forced_change_clears_the_expiry(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme, 'employee', ['must_change_password' => true, 'temp_password_expires_at' => now()->addDay()]);
        app(TenantContext::class)->clear();

        $this->actingIn($ana, $acme)->putJson('/api/auth/change-password', [
            'current_password' => 'Secret123', 'new_password' => 'Segura2026x', 'new_password_confirmation' => 'Segura2026x',
        ])->assertOk();

        $this->assertNull($ana->fresh()->temp_password_expires_at);
        $this->assertFalse($ana->fresh()->must_change_password);
    }

    public function test_api_creation_generates_email_from_document(): void
    {
        $acme = $this->makeTenant('acme');
        $hr   = $this->makeUser($acme, 'hr_manager');

        $this->actingIn($hr, $acme)->postJson('/api/admin/employees', [
            'first_name' => 'Luis', 'last_name' => 'Rojas', 'role' => 'employee',
            'document_type' => 'dni', 'document_number' => '44556677',
        ])->assertCreated()
          ->assertJsonPath('credentials.email', '44556677@turismonegreiros.com')
          ->assertJsonStructure(['credentials' => ['temporary_password']]);
    }

    public function test_branding_saves_the_login_domain_and_rejects_invalid_ones(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');

        $page = $this->actingAs($admin)->get(route('settings.branding'))->assertOk()->assertSee('name="login_email_domain"', false);
        unset($page);

        $this->assertSame('turismonegreiros.com', TenantSetting::forTenant($acme)->loginEmailDomain());
    }
}
