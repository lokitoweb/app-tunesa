<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Roles propios de cada empresa ("Jefe de flota"): parten de un rol del sistema y se ajustan aparte */
class CustomRolesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_company_creates_a_custom_role_with_its_own_permissions(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $plain = $this->makeUser($acme, 'supervisor');
        $fleet = $this->makeUser($acme, 'supervisor');

        $this->actingAs($admin)->post('/settings/roles', ['name' => 'Jefe de flota', 'base_role' => 'supervisor'])->assertSessionHasNoErrors();
        $role = Role::where('tenant_id', $acme->id)->where('name', 'Jefe de flota')->firstOrFail();
        $this->assertSame('c-jefe-de-flota', $role->slug);

        // Mismo nombre otra vez: se rechaza
        $this->post('/settings/roles', ['name' => 'jefe de flota', 'base_role' => 'supervisor'])->assertSessionHasErrors('name');

        // Se le agrega un permiso solo a ese rol
        $this->get('/settings/roles')->assertInertia(fn ($p) => $p->where('roles', fn ($roles) => collect($roles)->contains('slug', $role->slug)));
        $wanted = array_merge(\Database\Seeders\RolesAndPermissionsSeeder::expand(\Database\Seeders\RolesAndPermissionsSeeder::ROLES['supervisor'][2]), ['documents.review']);
        $this->put("/settings/roles/{$role->slug}", ['permissions' => $wanted])->assertSessionHasNoErrors();

        $fleet->forceFill(['custom_role_id' => $role->id])->save();
        $this->assertTrue($fleet->fresh()->hasPermission('documents.review'));
        $this->assertFalse($plain->fresh()->hasPermission('documents.review'), 'el supervisor normal no cambia');
        $this->assertSame('supervisor', $fleet->fresh()->role, 'el rol base rige jerarquía y app');

        // Con gente asignada no se elimina; sin gente sí
        $this->delete("/settings/roles/custom/{$role->slug}")->assertStatus(422);
        $fleet->forceFill(['custom_role_id' => null])->save();
        $this->delete("/settings/roles/custom/{$role->slug}")->assertSessionHasNoErrors();
        $this->assertModelMissing($role);
    }

    public function test_a_custom_role_only_applies_in_its_company_and_with_its_base_role(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $role = Role::create(['tenant_id' => $acme->id, 'slug' => 'c-caja', 'base_role' => 'accountant', 'name' => 'Caja', 'is_system' => false]);
        \App\Modules\Core\Models\RolePermissionOverride::withoutGlobalScopes()->create([
            'tenant_id' => $acme->id, 'role' => 'c-caja', 'granted' => true,
            'permission_id' => \App\Modules\Core\Models\Permission::where('slug', 'documents.review')->value('id'),
        ]);

        // Otro rol base: no aplica (rige el suyo)
        $employee = $this->makeUser($acme);
        $employee->forceFill(['custom_role_id' => $role->id])->save();
        $this->assertFalse($employee->fresh()->hasPermission('documents.review'));

        // Otra empresa: no aplica aunque apunte al rol
        $foreign = $this->makeUser($other, 'accountant');
        $foreign->forceFill(['custom_role_id' => $role->id])->save();
        $this->assertFalse($foreign->fresh()->hasPermission('documents.review'));

        // La otra empresa tampoco lo ve ni lo borra
        $otherAdmin = $this->makeUser($other, 'company_admin');
        $this->actingAs($otherAdmin)->get('/settings/roles')
            ->assertInertia(fn ($p) => $p->where('roles', fn ($roles) => ! collect($roles)->contains('slug', 'c-caja')));
        $this->delete('/settings/roles/custom/c-caja')->assertNotFound();
    }
}
