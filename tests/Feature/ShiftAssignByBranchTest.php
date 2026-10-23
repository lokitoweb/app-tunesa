<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Asignar turnos: el personal llega con sus sedes vigentes y su DNI (caso Agencia Trujillo) */
class ShiftAssignByBranchTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_assign_modal_receives_current_branches_and_document(): void
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
        $old = $this->makeUser($acme, 'employee', ['first_name' => 'Pedro']);
        $old->branches()->attach($trujillo->id, ['is_primary' => true, 'assigned_from' => '2020-01-01', 'assigned_until' => '2021-01-01']);

        $this->actingAs($hr)->get('/shifts')->assertOk()->assertInertia(function ($page) use ($misael, $rosa, $old, $trujillo, $lima) {
            $employees = collect($page->toArray()['props']['employees'])->keyBy('id');
            $this->assertSame([$trujillo->id], $employees[$misael->id]['branch_ids']);
            $this->assertSame('45678912', $employees[$misael->id]['document_number'], 'la búsqueda por DNI necesita el documento');
            $this->assertSame([$lima->id], $employees[$rosa->id]['branch_ids']);
            $this->assertSame([], $employees[$old->id]['branch_ids'], 'una asignación vencida no cuenta como sede vigente');
        });
    }
}
