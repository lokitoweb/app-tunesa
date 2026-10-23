<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Usuarios de prueba por rol: uno por rol, sin duplicar al repetir el comando */
class RoleTestUsersTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_creates_one_user_per_role_and_is_idempotent(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $this->makeBranch($acme);

        $this->artisan('app:role-test-users acme --domain=acme.test')->assertSuccessful();
        $this->artisan('app:role-test-users acme --domain=acme.test')->assertSuccessful();

        $users = User::withoutGlobalScopes()->where('email', 'like', '%.prueba@acme.test')->get();
        $this->assertCount(7, $users);
        $this->assertEqualsCanonicalizing(
            ['company_admin', 'hr_manager', 'manager', 'accountant', 'logistics', 'supervisor', 'employee'],
            $users->pluck('role')->all(),
        );

        $manager = $users->firstWhere('role', 'manager');
        $this->assertTrue($manager->hasPermission('reports.view'));
        $this->assertFalse($manager->hasPermission('users.manage'));
        $accountant = $users->firstWhere('role', 'accountant');
        $this->assertTrue($accountant->hasPermission('payroll.export'));
        $this->assertFalse($accountant->hasPermission('leave.approve_all'));
        $this->assertSame(1, $manager->branches()->count());

        $this->artisan('app:role-test-users no-existe')->assertFailed();

        // Gerencia: tablero y reportes, sin editar personal. Contabilidad: boletas y planilla.
        app(\App\Modules\Core\Services\TenantContext::class)->clear();
        $this->actingAs($manager)->get('/dashboard')->assertOk();
        $this->actingAs($manager)->get('/reports')->assertOk();
        $this->actingAs($manager)->get('/employees')->assertForbidden();
        $this->actingAs($manager)->get('/payslips')->assertForbidden();
        $this->actingAs($accountant)->get('/payslips')->assertOk();
        $this->actingAs($accountant)->get('/payroll')->assertOk();
        $this->actingAs($accountant)->get('/approvals')->assertForbidden();
    }
}
