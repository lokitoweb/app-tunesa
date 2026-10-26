<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * La asignación de horarios se hace en la matriz de "Programación de horarios": la agencia es
 * obligatoria y solo trae a su personal con sede vigente (caso Agencia Trujillo).
 */
class ShiftAssignByBranchTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_schedule_matrix_requires_the_agency_and_lists_only_its_current_staff(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $hr = $this->makeUser($acme, 'hr_manager');
        $trujillo = $this->makeBranch($acme, ['name' => 'Agencia Trujillo']);
        $lima = $this->makeBranch($acme, ['name' => 'Agencia Lima']);

        $misael = $this->makeUser($acme, 'employee', ['first_name' => 'Misael', 'document_number' => '45678912']);
        $misael->branches()->attach($trujillo->id, ['is_primary' => true, 'assigned_from' => '2024-01-01']);
        $rosa = $this->makeUser($acme, 'employee', ['first_name' => 'Rosa']);
        $rosa->branches()->attach($lima->id, ['is_primary' => true, 'assigned_from' => '2024-01-01']);
        $accountant = $this->makeUser($acme, 'accountant', ['first_name' => 'Carla']); // Contabilidad marca por GPS (política)
        $accountant->branches()->attach($trujillo->id, ['is_primary' => true, 'assigned_from' => '2024-01-01']);
        $old = $this->makeUser($acme, 'employee', ['first_name' => 'Pedro']);
        $old->branches()->attach($trujillo->id, ['is_primary' => true, 'assigned_from' => '2020-01-01', 'assigned_until' => '2021-01-01']);

        // Con varias agencias, sin elegir no se carga a nadie
        $this->actingAs($hr)->getJson('/shifts/schedule?month=2026-10')->assertOk()
            ->assertJsonPath('branchId', null)->assertJsonPath('people', []);

        $ids = array_column($this->getJson("/shifts/schedule?branch_id={$trujillo->id}&month=2026-10")->assertOk()->json('people'), 'id');
        $this->assertContains($misael->id, $ids);
        $this->assertContains($accountant->id, $ids, 'Contabilidad se programa en su agencia');
        $this->assertNotContains($rosa->id, $ids, 'otra agencia');
        $this->assertNotContains($old->id, $ids, 'una asignación vencida no cuenta como sede vigente');
    }
}
