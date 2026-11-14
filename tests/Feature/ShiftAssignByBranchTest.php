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

    public function test_each_role_sees_the_right_agencies_and_a_supervisor_without_agencies_is_told_what_to_do(): void
    {
        $this->seedRoles();
        $acme  = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $hr    = $this->makeUser($acme, 'hr_manager');
        $juan  = $this->makeUser($acme, 'supervisor', ['first_name' => 'Juan']);
        $trujillo = $this->makeBranch($acme, ['name' => 'Agencia Trujillo']);
        $lima     = $this->makeBranch($acme, ['name' => 'Agencia Lima']);
        $foreign  = $this->makeBranch($other, ['name' => 'Agencia Ajena', 'supervisor_id' => $juan->id]);
        $names = fn ($user) => collect($this->actingAs($user)->get('/shifts/schedule')->assertOk()->viewData('page')['props']['branches'])->pluck('name')->sort()->values()->all();
        $hint  = fn ($user) => $this->actingAs($user)->get('/shifts/schedule')->viewData('page')['props']['noBranchesHint'];

        // Agencias sin supervisor asignado: las ve todo supervisor de la empresa (nunca las de otra)
        $this->assertSame(['Agencia Lima', 'Agencia Trujillo'], $names($juan));
        $this->assertNull($hint($juan));

        // Todas con otros supervisores: no ve ninguna y la pantalla dice qué hacer
        $pedro = $this->makeUser($acme, 'supervisor', ['first_name' => 'Pedro']);
        $trujillo->syncSupervisors([$pedro->id]);
        $lima->syncSupervisors([$pedro->id]);
        $this->assertSame([], $names($juan));
        $this->assertStringContainsString('Supervisor» en Sedes', $hint($juan));

        // Lo agregan a Trujillo junto a Pedro (dos supervisores): ve solo Trujillo y programa a su gente
        $trujillo->syncSupervisors([$pedro->id, $juan->id]);
        $this->assertSame(['Agencia Trujillo'], $names($juan));
        $this->assertSame(['Agencia Lima', 'Agencia Trujillo'], $names($pedro));
        $this->assertNull($hint($juan));
        // Si pide Lima (no es suya) se le abre su única agencia, nunca Lima
        $this->actingAs($juan)->getJson("/shifts/schedule?branch_id={$lima->id}&month=2026-10")->assertOk()->assertJsonPath('branchId', $trujillo->id);
        $this->actingAs($juan)->get("/shifts/schedule/export?branch_id={$lima->id}&month=2026-10")->assertNotFound();

        // RR. HH. ve todas las de su empresa, nunca las de otra
        $this->assertSame(['Agencia Lima', 'Agencia Trujillo'], $names($hr));
        $this->assertNotContains($foreign->name, $names($hr));
    }
}
