<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Visor de la bitácora de auditoría (/audit).
 */
class AuditLogWebTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function seedLogs(): array
    {
        $acme  = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $admin = $this->makeUser($acme, 'company_admin', ['first_name' => 'Ana']);
        $hr    = $this->makeUser($acme, 'hr_manager', ['first_name' => 'Hugo']);
        $alien = $this->makeUser($other, 'company_admin', ['first_name' => 'Zoe']);

        AuditLog::create(['tenant_id' => $acme->id, 'user_id' => $hr->id, 'action' => 'payroll.exported',
            'meta' => ['period' => '2026-09', 'rows' => 12], 'ip' => '10.0.0.1'])->forceFill(['created_at' => '2026-09-30 10:00:00'])->save();
        AuditLog::create(['tenant_id' => $acme->id, 'user_id' => $admin->id, 'action' => 'payment_info.revealed', 'subject_type' => 'User', 'subject_id' => $hr->id]);
        AuditLog::create(['tenant_id' => $other->id, 'user_id' => $alien->id, 'action' => 'document.viewed']);

        return [$acme, $other, $admin, $hr];
    }

    public function test_company_admin_sees_only_its_tenant_with_filters_and_meta(): void
    {
        [, , $admin, $hr] = $this->seedLogs();

        $this->actingAs($admin)->get('/audit')->assertOk()
            ->assertSee('Planilla exportada')->assertSee('Cuenta bancaria revelada')
            ->assertSee('period')->assertSee('2026-09')->assertSee('rows')
            ->assertDontSee('document.viewed')->assertDontSee('Zoe');

        $this->get('/audit?action=payroll.exported')->assertSee('payroll.exported</code>', false)->assertDontSee('payment_info.revealed</code>', false);
        $this->get("/audit?user_id={$admin->id}")->assertSee('payment_info.revealed</code>', false)->assertDontSee('10.0.0.1');
        $this->get('/audit?from=2026-09-01&to=2026-09-30')->assertSee('10.0.0.1')->assertDontSee('payment_info.revealed</code>', false);
        $this->get('/audit?from=2026-10-05&to=2026-09-01')->assertSessionHasErrors('to');

        $this->get('/dashboard')->assertSee(route('audit.index'));
    }

    public function test_super_admin_global_view_sees_all_with_company_badge(): void
    {
        $this->seedLogs();
        $root = $this->makeUser(null, 'super_admin');

        $this->actingAs($root)->get('/audit')->assertOk()
            ->assertSee('document.viewed</code>', false)->assertSee('payroll.exported</code>', false)
            ->assertSee('tenant-badge')->assertSee('Other');
    }

    public function test_roles_without_audit_view_are_forbidden(): void
    {
        [$acme, , , $hr] = $this->seedLogs();
        $supervisor = $this->makeUser($acme, 'supervisor');

        $this->actingAs($hr)->get('/audit')->assertForbidden();
        $this->actingAs($supervisor)->get('/audit')->assertForbidden();
    }
}
