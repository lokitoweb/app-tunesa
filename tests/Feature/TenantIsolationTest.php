<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Scopes\TenantScope;
use App\Modules\Core\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_listing_only_returns_rows_of_the_resolved_tenant(): void
    {
        $acme  = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $mine  = $this->makeBranch($acme, ['name' => 'Sede Acme']);
        $this->makeBranch($other, ['name' => 'Sede Other']);

        $admin = $this->makeUser($acme, 'company_admin');

        $this->actingIn($admin, $acme)->getJson('/api/admin/branches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_global_scope_hides_other_tenant_rows_by_id(): void
    {
        $acme  = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $foreign = $this->makeBranch($other);

        $admin = $this->makeUser($acme, 'company_admin');

        $this->actingIn($admin, $acme)->getJson("/api/admin/branches/{$foreign->id}")->assertNotFound();
        $this->actingIn($admin, $acme)->deleteJson("/api/admin/branches/{$foreign->id}")->assertNotFound();
        $this->assertNotNull(Branch::withoutGlobalScope(TenantScope::class)->find($foreign->id));
    }

    public function test_token_cannot_be_used_against_another_tenant(): void
    {
        $acme  = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $admin = $this->makeUser($acme, 'company_admin');

        $this->actingIn($admin, $other)->getJson('/api/admin/branches')
            ->assertForbidden()
            ->assertJsonPath('code', 'TENANT_SCOPE_VIOLATION');
    }

    public function test_tenant_id_is_filled_and_foreign_writes_are_blocked_inside_a_tenant_context(): void
    {
        $acme  = $this->makeTenant('acme');
        $other = $this->makeTenant('other');

        app(TenantContext::class)->set($acme);

        $branch = Branch::create([
            'name' => 'Auto', 'latitude' => -12.1, 'longitude' => -77.0, 'allowed_radius_meters' => 50,
        ]);
        $this->assertSame($acme->id, $branch->tenant_id);

        $this->expectException(\DomainException::class);
        Branch::create([
            'tenant_id' => $other->id, 'name' => 'Ajena', 'latitude' => -12.1, 'longitude' => -77.0,
        ]);
    }

    public function test_scope_is_inactive_without_tenant_context(): void
    {
        $this->makeBranch($this->makeTenant('acme'));
        $this->makeBranch($this->makeTenant('other'));

        $this->assertSame(2, Branch::count());
        $this->assertSame(2, Branch::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_suspended_tenant_is_rejected(): void
    {
        $acme  = $this->makeTenant('acme', ['status' => 'suspended']);
        $admin = $this->makeUser($acme, 'company_admin');

        $this->actingIn($admin, $acme)->getJson('/api/admin/branches')
            ->assertForbidden()
            ->assertJsonPath('code', 'TENANT_INACTIVE');
    }

    public function test_tenant_resolves_by_custom_domain_for_branding(): void
    {
        $this->makeTenant('acme', ['custom_domain' => 'asistencia.acme.pe', 'primary_color' => '#112233']);

        $this->getJson('http://asistencia.acme.pe/api/tenant/branding')
            ->assertOk()
            ->assertJsonPath('data.slug', 'acme')
            ->assertJsonPath('data.primary_color', '#112233');
    }
}
