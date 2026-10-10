<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

class RolesAndPermissionsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_seeder_creates_the_five_roles_and_is_idempotent(): void
    {
        $this->seedRoles();
        $this->seedRoles();

        $this->assertEqualsCanonicalizing(Role::ALL, Role::system()->pluck('slug')->all());
        $this->assertSame(count(RolesAndPermissionsSeeder::PERMISSIONS), Permission::count());
    }

    public function test_role_matrix(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');

        $employee   = $this->makeUser($acme, 'employee');
        $supervisor = $this->makeUser($acme, 'supervisor');
        $hr         = $this->makeUser($acme, 'hr_manager');
        $admin      = $this->makeUser($acme, 'company_admin');
        $super      = $this->makeUser(null, 'super_admin');

        $this->assertTrue($employee->hasPermission('attendance.mark'));
        $this->assertFalse($employee->hasPermission('shifts.manage'));
        $this->assertTrue($supervisor->hasPermission('leave.approve_team'));
        $this->assertFalse($supervisor->hasPermission('leave.approve_all'));
        $this->assertTrue($hr->hasPermission('payslips.manage'));
        $this->assertFalse($hr->hasPermission('tenants.branding'));
        $this->assertTrue($admin->hasPermission('tenants.branding'));
        $this->assertFalse($admin->hasPermission('tenants.manage'));
        $this->assertTrue($super->hasPermission('tenants.manage'));
        $this->assertTrue($hr->can('shifts.manage'));
    }

    public function test_permission_middleware_blocks_employees_from_admin_routes(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');

        $this->actingIn($this->makeUser($acme, 'employee'), $acme)
            ->getJson('/api/admin/shifts')
            ->assertForbidden()
            ->assertJsonPath('code', 'INSUFFICIENT_PERMISSION');

        $this->actingIn($this->makeUser($acme, 'hr_manager'), $acme)
            ->getJson('/api/admin/roles')
            ->assertOk()
            ->assertJsonCount(4, 'data.roles');
    }

    public function test_every_api_route_points_to_an_existing_controller_method(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $action = $route->getActionName();
            if (! str_contains($action, '@')) {
                continue;
            }
            [$class, $method] = explode('@', $action);
            $this->assertTrue(method_exists($class, $method), "Falta {$action} ({$route->uri()})");
        }
    }
}
