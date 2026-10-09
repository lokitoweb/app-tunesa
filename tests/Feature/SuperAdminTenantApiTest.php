<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

class SuperAdminTenantApiTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_super_admin_onboards_and_suspends_a_tenant(): void
    {
        $this->makeUser(null, 'super_admin', ['email' => 'root@tunesa.app', 'password' => 'Secret123']);

        $token = $this->postJson('/api/superadmin/auth/login', [
            'email' => 'root@tunesa.app', 'password' => 'Secret123',
        ])->assertOk()->json('data.token');

        $auth = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $tenantId = $this->withHeaders($auth)->postJson('/api/superadmin/tenants', [
            'slug' => 'nueva', 'company_name' => 'Nueva SAC', 'email' => 'contacto@nueva.pe',
            'primary_color' => '#0F766E',
            'admin' => ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'email' => 'ana@nueva.pe', 'password' => 'Secret123'],
        ])->assertCreated()->assertJsonPath('data.branding.primary_color', '#0F766E')->json('data.id');

        $admin = User::where('email', 'ana@nueva.pe')->first();
        $this->assertSame('company_admin', $admin->role);
        $this->assertSame($tenantId, $admin->tenant_id);

        // El admin de la nueva empresa entra con su slug
        $this->postJson('/api/auth/login', ['email' => 'ana@nueva.pe', 'password' => 'Secret123'], ['X-Tenant-Slug' => 'nueva'])
            ->assertOk();

        $this->withHeaders($auth)->postJson("/api/superadmin/tenants/{$tenantId}/suspend")->assertOk();
        $this->assertSame('suspended', Tenant::find($tenantId)->status);

        $this->postJson('/api/auth/login', ['email' => 'ana@nueva.pe', 'password' => 'Secret123'], ['X-Tenant-Slug' => 'nueva'])
            ->assertForbidden();
    }

    public function test_company_admin_cannot_reach_super_admin_routes(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $token = $admin->createToken('t')->plainTextToken;

        $this->getJson('/api/superadmin/tenants', ['Authorization' => "Bearer {$token}"])->assertForbidden();
    }

    public function test_company_admin_updates_branding(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $hr    = $this->makeUser($acme, 'hr_manager');

        $this->actingIn($hr, $acme)->putJson('/api/admin/branding', ['primary_color' => '#000000'])->assertForbidden();

        $this->actingIn($admin, $acme)->putJson('/api/admin/branding', ['primary_color' => '#ABCDEF'])
            ->assertOk()->assertJsonPath('data.primary_color', '#ABCDEF');
    }
}
