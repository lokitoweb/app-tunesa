<?php

namespace Tests\Feature;

use App\Modules\Core\Models\AuditLog;
use App\Modules\Ethics\Models\EthicsCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Auditoría de seguridad (2026-10-20): los permisos salen solo de la matriz de
 * roles, los números de cuenta solo se ven con profiles.view_sensitive y con
 * bitácora, y ninguna pantalla deja tocar datos de otra empresa.
 */
class RbacHardeningTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_manager_and_accountant_do_not_get_profile_permissions_outside_the_matrix(): void
    {
        $acme = $this->makeTenant('acme');

        foreach (['manager', 'accountant'] as $role) {
            $user = $this->makeUser($acme, $role);
            $this->assertFalse($user->hasPermission('profiles.manage'), $role);
            $this->assertFalse($user->hasPermission('profiles.view_sensitive'), $role);
        }
        $this->assertTrue($this->makeUser($acme, 'hr_manager')->hasPermission('profiles.view_sensitive'));
    }

    public function test_revealing_account_numbers_is_audited(): void
    {
        $acme = $this->makeTenant('acme');
        $hr = $this->makeUser($acme, 'hr_manager');
        $emp = $this->makeUser($acme, 'employee');

        // Con el permiso: se muestra y queda en la bitácora
        $this->actingAs($hr)->get(route('employees.file', ['id' => $emp->id, 'reveal' => 1]))
            ->assertOk()->assertViewHas('revealed', true);
        // Sin pedirlo, la ficha sale con los números enmascarados
        $this->actingAs($hr)->withSession([])->get(route('employees.file', $emp->id))
            ->assertOk()->assertViewHas('revealed', false);
        $this->assertTrue(AuditLog::where('action', 'payment_info.revealed')->where('subject_id', $emp->id)->exists());

    }

    public function test_ethics_case_cannot_be_assigned_to_a_user_of_another_company(): void
    {
        $acme = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $admin = $this->makeUser($acme, 'company_admin');
        $outsider = $this->makeUser($other, 'company_admin');
        $case = EthicsCase::withoutGlobalScopes()->forceCreate([
            'tenant_id' => $acme->id, 'public_code' => '123456', 'token_hash' => hash('sha256', 'x'),
            'category' => 'harassment', 'subject' => 'Prueba', 'body' => 'Texto de prueba suficiente.',
            'status' => 'received', 'reported_on' => now()->toDateString(),
        ]);

        $this->actingAs($admin)->putJson(route('ethics.assign', $case->id), ['assigned_to' => $outsider->id])
            ->assertStatus(422)->assertJsonValidationErrors('assigned_to');
        $this->assertNull($case->fresh()->assigned_to);

        $this->actingAs($admin)->putJson(route('ethics.assign', $case->id), ['assigned_to' => $admin->id])->assertOk();
        $this->assertSame($admin->id, $case->fresh()->assigned_to);
    }

    public function test_tenant_options_only_for_own_company_or_super_admin(): void
    {
        $acme = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $admin = $this->makeUser($acme, 'company_admin');

        $this->actingAs($admin)->getJson(route('employees.tenant-options', $other->id))->assertForbidden();
        $this->actingAs($admin)->getJson(route('employees.tenant-options', $acme->id))->assertOk();
        $this->actingAs($this->makeUser(null, 'super_admin'))->getJson(route('employees.tenant-options', $other->id))->assertOk();
    }
}
