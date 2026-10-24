<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Department;
use App\Modules\Core\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** DEF-06: RRHH crea un cargo nuevo desde el alta del colaborador, sin ir a Organización */
class InlinePositionCatalogTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_hr_creates_a_position_from_the_employee_form(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $hr = $this->makeUser($acme, 'hr_manager');
        $sales = Department::create(['tenant_id' => $acme->id, 'name' => 'Ventas', 'is_active' => true]);
        $foreignArea = Department::create(['tenant_id' => $this->makeTenant('other')->id, 'name' => 'X', 'is_active' => true]);

        // El alta (modal y página) ofrece "Nuevo cargo" aunque el catálogo esté vacío
        $this->actingAs($hr)->get('/employees')->assertOk()->assertSee('data-new-position', false);
        $this->actingAs($hr)->get('/employees/create')->assertOk()->assertSee('id="position_id"', false)->assertSee('Nuevo cargo');

        $id = $this->actingAs($hr)->postJson('/employees/positions', ['name' => '  Asesor   de ventas ', 'department_id' => $sales->id])
            ->assertCreated()->assertJsonPath('data.name', 'Asesor de ventas')->assertJsonPath('data.department_id', $sales->id)
            ->json('data.id');
        $this->assertSame($acme->id, Position::find($id)->tenant_id);

        // Mismo nombre (otra capitalización): no se duplica, se reutiliza
        $this->actingAs($hr)->postJson('/employees/positions', ['name' => 'ASESOR DE VENTAS'])
            ->assertOk()->assertJsonPath('created', false)->assertJsonPath('data.id', $id);
        $this->assertSame(1, Position::count());

        $this->actingAs($hr)->postJson('/employees/positions', ['name' => ''])->assertJsonValidationErrors('name');
        $this->actingAs($hr)->postJson('/employees/positions', ['name' => 'Cajero', 'department_id' => $foreignArea->id])->assertJsonValidationErrors('department_id');

        // Un colaborador no entra al panel: no crea cargos
        $this->actingAs($this->makeUser($acme))->postJson('/employees/positions', ['name' => 'Jefe'])->assertRedirect();
        $this->assertSame(1, Position::count());
    }

    public function test_saving_without_catalog_position_keeps_the_previous_free_text(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $hr = $this->makeUser($acme, 'hr_manager');
        $ana = $this->makeUser($acme, 'employee', ['position' => 'Almacenera']);

        $this->actingAs($hr)->get("/employees/{$ana->id}/edit")->assertOk()
            ->assertSee('name="position" value="Almacenera"', false);
    }
}
