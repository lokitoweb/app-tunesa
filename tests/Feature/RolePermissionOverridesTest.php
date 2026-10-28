<?php

namespace Tests\Feature;

use App\Modules\Core\Models\RolePermissionOverride;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Configuración › Roles y permisos: cada empresa ajusta sus roles sobre el estándar, sin afectar a las demás */
class RolePermissionOverridesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_company_customizes_a_role_without_touching_other_companies_or_the_standard(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $admin = $this->makeUser($acme, 'company_admin');
        $accountant = $this->makeUser($acme, 'accountant');
        $otherAccountant = $this->makeUser($other, 'accountant');

        // Sin personalizar: el estándar de siempre
        $this->assertFalse($accountant->fresh()->hasPermission('documents.review'));

        $this->actingAs($admin)->get('/settings/roles')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Core/Pages/Roles/Index')->where('company', $acme->company_name));

        // Contabilidad de ACME puede cargar documentos al legajo; ACME le quita ver boletas propias
        $wanted = array_values(array_diff(
            array_merge(RolesAndPermissionsSeeder::expand(RolesAndPermissionsSeeder::ROLES['accountant'][2]), ['documents.review', 'profiles.manage']),
            ['benefits.view_own'],
        ));
        $this->put('/settings/roles/accountant', ['permissions' => $wanted])->assertSessionHasNoErrors();

        $this->assertTrue($accountant->fresh()->hasPermission('documents.review'));
        $this->assertFalse($accountant->fresh()->hasPermission('benefits.view_own'));
        $this->assertFalse($otherAccountant->fresh()->hasPermission('documents.review'), 'otra empresa no cambia');
        $this->assertTrue($otherAccountant->fresh()->hasPermission('benefits.view_own'));

        // Y se aplica en las rutas de verdad: entra al legajo de un colaborador de su empresa
        $ana = $this->makeUser($acme);
        $this->actingAs($accountant->fresh())->get("/employees/{$ana->id}/file")->assertOk();
        $this->actingAs($otherAccountant->fresh())->get("/employees/{$ana->id}/file")->assertForbidden();

        // Un despliegue resincroniza el estándar y no pisa lo de la empresa
        (new RolesAndPermissionsSeeder())->run();
        $this->assertTrue($accountant->fresh()->hasPermission('documents.review'));

        // Solo se guarda la diferencia
        $this->assertSame(3, RolePermissionOverride::withoutGlobalScopes()->where('tenant_id', $acme->id)->where('role', 'accountant')->count());

        // Volver al estándar
        $this->actingAs($admin)->delete('/settings/roles/accountant')->assertSessionHasNoErrors();
        $this->assertFalse($accountant->fresh()->hasPermission('documents.review'));
        $this->assertTrue($accountant->fresh()->hasPermission('benefits.view_own'));
    }

    public function test_limits_no_one_grants_platform_or_role_editing_and_admin_roles_are_not_editable(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $hr = $this->makeUser($acme, 'hr_manager');
        $supervisor = $this->makeUser($acme, 'supervisor');

        // RR. HH. no edita roles (se daría Tesorería a sí mismo); un supervisor tampoco
        $this->actingAs($hr)->get('/settings/roles')->assertForbidden();
        $this->actingAs($supervisor)->put('/settings/roles/supervisor', ['permissions' => ['treasury.pay']])->assertForbidden();

        // Permisos bloqueados: se ignoran aunque se envíen
        $this->actingAs($admin)->put('/settings/roles/hr_manager', ['permissions' => ['roles.manage', 'tenants.manage', 'users.view']])->assertSessionHasNoErrors();
        $this->assertFalse($hr->fresh()->hasPermission('roles.manage'));
        $this->assertFalse($hr->fresh()->hasPermission('tenants.manage'));
        $this->assertTrue($hr->fresh()->hasPermission('users.view'));

        // El administrador de empresa y el super admin no se personalizan
        $this->put('/settings/roles/company_admin', ['permissions' => []])->assertStatus(422);
        $this->put('/settings/roles/super_admin', ['permissions' => []])->assertStatus(422);
        $this->assertTrue($admin->fresh()->hasPermission('users.manage'));
    }

    public function test_permissions_of_a_disabled_module_are_neither_listed_nor_lost(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $acme->forceFill(['settings' => array_merge((array) $acme->settings, ['disabled_modules' => ['Finance']])])->save();
        $admin = $this->makeUser($acme, 'company_admin');
        $accountant = $this->makeUser($acme, 'accountant');

        $this->actingAs($admin)->get('/settings/roles')->assertInertia(fn ($p) => $p
            ->where('groups', fn ($groups) => ! collect($groups)->pluck('key')->contains('finance')));

        // Guardar sin los permisos de Finanzas (no se ven) no se los quita al rol
        $this->put('/settings/roles/accountant', ['permissions' => ['users.view']])->assertSessionHasNoErrors();
        $this->assertTrue($accountant->fresh()->hasPermission('treasury.pay'));
        $this->assertFalse($accountant->fresh()->hasPermission('reports.view'));
    }
}
