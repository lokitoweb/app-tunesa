<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Role;
use App\Modules\Core\Support\SystemRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Roles y permisos del sistema al día sin correr el seeder a mano */
class SystemRolesSyncTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_sync_restores_missing_role_permissions(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $emp  = $this->makeUser($acme, 'employee');
        Role::whereNull('tenant_id')->where('slug', 'employee')->first()->permissions()->detach();
        $this->assertFalse($emp->fresh()->hasPermission('attendance.view_own'));

        $this->assertTrue(SystemRoles::sync());
        $this->assertTrue($emp->fresh()->hasPermission('attendance.view_own'));
        $this->assertTrue($emp->fresh()->hasPermission('attendance.mark'));
    }

    public function test_migrate_hook_is_registered(): void
    {
        $this->assertTrue(app('events')->hasListeners(\Illuminate\Database\Events\MigrationsEnded::class));
        $this->assertTrue(app('events')->hasListeners(\Illuminate\Database\Events\NoPendingMigrations::class));
    }
}
