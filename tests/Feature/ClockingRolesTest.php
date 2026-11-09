<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\RolePermissionOverride;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Quién marca asistencia: el permiso «Marcar asistencia» (attendance.mark). Por defecto todos
 * menos Gerencia; cada empresa lo ajusta en Configuración › Roles y permisos.
 */
class ClockingRolesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_everyone_but_managers_clocks_and_each_company_can_change_it(): void
    {
        $acme = $this->makeTenant('acme');
        $roles = ['employee', 'supervisor', 'accountant', 'logistics', 'hr_manager', 'company_admin', 'manager'];
        $people = collect($roles)->mapWithKeys(fn ($role) => [$role => $this->makeUser($acme, $role)]);
        $this->makeUser(null, 'super_admin');
        $clocking = fn () => User::withoutGlobalScopes()->clocking()->pluck('role')->sort()->values()->all();

        $this->assertSame(['accountant', 'company_admin', 'employee', 'hr_manager', 'logistics', 'supervisor'], $clocking());
        $this->assertTrue($people['hr_manager']->clocks());
        $this->assertFalse($people['manager']->clocks());

        // La empresa ajusta Roles y permisos: RRHH deja de marcar y Gerencia empieza a marcar
        $mark = Permission::where('slug', 'attendance.mark')->value('id');
        RolePermissionOverride::create(['tenant_id' => $acme->id, 'role' => 'hr_manager', 'permission_id' => $mark, 'granted' => false]);
        RolePermissionOverride::create(['tenant_id' => $acme->id, 'role' => 'manager', 'permission_id' => $mark, 'granted' => true]);
        $this->assertSame(['accountant', 'company_admin', 'employee', 'logistics', 'manager', 'supervisor'], $clocking());
        $this->assertTrue(User::find($people['manager']->id)->clocks());
        $this->assertFalse(User::find($people['hr_manager']->id)->clocks());
    }

    public function test_the_app_knows_who_clocks_and_a_manager_cannot_punch(): void
    {
        $acme = $this->makeTenant('acme');
        $manager = $this->makeUser($acme, 'manager');
        $hr = $this->makeUser($acme, 'hr_manager');

        $this->actingIn($manager, $acme)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.permissions.can_mark_attendance', false);
        $this->actingIn($hr, $acme)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.permissions.can_mark_attendance', true);
        $this->actingIn($manager, $acme)->postJson('/api/attendance/clock-in', ['latitude' => -12.09, 'longitude' => -77.03])->assertForbidden();
    }
}
