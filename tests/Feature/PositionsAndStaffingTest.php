<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Department;
use App\Modules\Core\Models\Position;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Catálogo de cargos (Atención al cliente, Almacenero, Chofer, Estibador...) y
 * plantilla de cada sede: plazas previstas por cargo frente al personal activo.
 * Se administra en el panel (Organización) y llega a la app por la API.
 */
class PositionsAndStaffingTest extends TestCase
{
    use RefreshDatabase, CreatesTenants;

    private Tenant $acme;
    private User $admin;
    private Branch $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        $this->acme   = $this->makeTenant('acme');
        $this->admin  = $this->makeUser($this->acme, 'company_admin');
        $this->agency = $this->makeBranch($this->acme, ['name' => 'Agencia Trujillo']);
    }

    private function position(string $name, array $attrs = []): Position
    {
        return Position::create(['tenant_id' => $this->acme->id, 'name' => $name] + $attrs);
    }

    private function employeeAt(Branch $branch, array $attrs = []): User
    {
        $user = $this->makeUser($this->acme, 'employee', $attrs);
        $user->branches()->attach($branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);

        return $user;
    }

    public function test_admin_manages_the_positions_catalog(): void
    {
        $warehouse = Department::create(['tenant_id' => $this->acme->id, 'name' => 'Almacén y estiba']);
        $this->actingAs($this->admin);

        $this->post('/organization/positions', [
            'name' => 'Almacenero', 'code' => 'ALM', 'department_id' => $warehouse->id,
            'description' => 'Recibe, ordena y despacha la carga.', 'is_roaming' => false, 'is_active' => true,
        ])->assertSessionHasNoErrors()->assertSessionHas('success', '«Almacenero» creado.');

        $position = Position::where('name', 'Almacenero')->firstOrFail();
        $this->assertSame($warehouse->id, $position->department_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'position.created']);

        // Duplicado por empresa, área ajena y campos obligatorios
        $this->post('/organization/positions', ['name' => 'Almacenero', 'is_roaming' => false, 'is_active' => true])->assertSessionHasErrors('name');
        $foreignDept = DB::table('departments')->insertGetId(['tenant_id' => $this->makeTenant('other')->id, 'name' => 'Ajena', 'is_active' => true]);
        $this->post('/organization/positions', ['name' => 'Chofer', 'department_id' => $foreignDept, 'is_roaming' => true, 'is_active' => true])->assertSessionHasErrors('department_id');
        $this->post('/organization/positions', ['name' => 'Chofer', 'is_active' => true])->assertSessionHasErrors('is_roaming');

        // Renombrar el cargo actualiza la copia del nombre en sus colaboradores
        $ana = $this->employeeAt($this->agency, ['position_id' => $position->id]);
        $this->assertSame('Almacenero', $ana->fresh()->position);
        $this->put("/organization/positions/{$position->id}", [
            'name' => 'Almacenero de carga', 'code' => 'ALM', 'department_id' => $warehouse->id, 'is_roaming' => false, 'is_active' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame('Almacenero de carga', $ana->fresh()->position);

        // Eliminar: el colaborador queda sin vínculo pero conserva el nombre
        $this->delete("/organization/positions/{$position->id}")->assertSessionHas('success', '«Almacenero de carga» eliminado.');
        $this->assertNull($ana->fresh()->position_id);
        $this->assertSame('Almacenero de carga', $ana->fresh()->position);
    }

    public function test_suggested_positions_link_their_area_and_roaming(): void
    {
        $route = Department::create(['tenant_id' => $this->acme->id, 'name' => 'Operaciones / Ruta']);
        $this->position('Estibador');

        $this->actingAs($this->admin)->get('/organization')->assertOk()
            ->assertInertia(fn ($p) => $p->where('suggestedPositions', array_column(Position::SUGGESTED, 0))->has('positions', 1));

        $this->post('/organization/positions/suggested', ['names' => ['Chofer', 'Estibador', 'Atención al cliente', 'Chofer']])
            ->assertSessionHasNoErrors()->assertSessionHas('success', '2 cargos agregados.');

        $driver = Position::where('name', 'Chofer')->firstOrFail();
        $this->assertSame($route->id, $driver->department_id);
        $this->assertTrue($driver->is_roaming);
        // El área "Atención al cliente / Caja" no existe en esta empresa: queda sin área
        $this->assertNull(Position::where('name', 'Atención al cliente')->value('department_id'));
        $this->assertSame(1, Position::where('name', 'Estibador')->count());

        // Quien ya tenía el cargo escrito a mano queda vinculado al crearlo en el catálogo
        $luis = $this->employeeAt($this->agency, ['position' => ' almacenero ']);
        $this->post('/organization/positions/suggested', ['names' => ['Almacenero']])->assertSessionHasNoErrors();
        $this->assertSame([Position::where('name', 'Almacenero')->value('id'), 'Almacenero'], [$luis->fresh()->position_id, $luis->fresh()->position]);

        // Solo nombres del catálogo sugerido
        $this->post('/organization/positions/suggested', ['names' => ['Hacker']])->assertSessionHasErrors('names.0');
    }

    public function test_branch_staffing_compares_planned_and_actual_headcount(): void
    {
        $counter = $this->position('Atención al cliente');
        $loader  = $this->position('Estibador');
        $driver  = $this->position('Chofer', ['is_roaming' => true]);
        $this->employeeAt($this->agency, ['position_id' => $counter->id]);
        $this->employeeAt($this->agency, ['position_id' => $driver->id]);
        $this->employeeAt($this->agency, ['position_id' => $driver->id, 'status' => 'inactive']);
        $this->employeeAt($this->agency, ['position' => 'Escrito a mano']);

        $this->actingAs($this->admin)->put("/organization/branches/{$this->agency->id}/positions", ['positions' => [
            ['position_id' => $counter->id, 'planned_headcount' => 3],
            ['position_id' => $loader->id, 'planned_headcount' => 4],
        ]])->assertSessionHasNoErrors()->assertSessionHas('success', 'Plantilla de Agencia Trujillo guardada.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'branch.staffing_updated']);

        $page = $this->get('/organization')->assertOk();
        $branch = collect($page->prop('branches'))->firstWhere('id', $this->agency->id);
        $this->assertEqualsCanonicalizing([
            ['position_id' => $counter->id, 'planned' => 3],
            ['position_id' => $loader->id, 'planned' => 4],
        ], $branch['plan']);
        $this->assertContains(['department_id' => null, 'position_id' => $driver->id, 'position' => 'Chofer', 'total' => 1], $branch['staff']);

        // La app (supervisores / RRHH) ve la plantilla con la brecha por cargo
        $staffing = collect($this->actingIn($this->admin, $this->acme)->getJson("/api/admin/branches/{$this->agency->id}")
            ->assertOk()->json('data.staffing'))->keyBy('name');
        $gap = fn (string $name) => Arr::only($staffing[$name], ['planned', 'actual', 'gap']);
        $this->assertSame(['planned' => 3, 'actual' => 1, 'gap' => 2], $gap('Atención al cliente'));
        $this->assertSame(['planned' => 4, 'actual' => 0, 'gap' => 4], $gap('Estibador'));
        // Con personal pero sin plazas previstas: exceso visible
        $this->assertSame(['planned' => 0, 'actual' => 1, 'gap' => -1], $gap('Chofer'));
        $this->assertTrue($staffing['Chofer']['is_roaming']);
    }

    public function test_staffing_rejects_foreign_or_repeated_positions_and_scoped_users(): void
    {
        $loader  = $this->position('Estibador');
        $foreign = DB::table('positions')->insertGetId(['tenant_id' => $this->makeTenant('other')->id, 'name' => 'Ajeno', 'is_active' => true, 'is_roaming' => false]);
        $this->actingAs($this->admin);

        $url = "/organization/branches/{$this->agency->id}/positions";
        $this->put($url, ['positions' => [['position_id' => $foreign, 'planned_headcount' => 1]]])->assertSessionHasErrors('positions.0.position_id');
        $this->put($url, ['positions' => [
            ['position_id' => $loader->id, 'planned_headcount' => 1],
            ['position_id' => $loader->id, 'planned_headcount' => 2],
        ]])->assertSessionHasErrors('positions.1.position_id');
        $this->put($url, ['positions' => [['position_id' => $loader->id, 'planned_headcount' => -1]]])->assertSessionHasErrors('positions.0.planned_headcount');

        // Vaciar la plantilla
        $this->put($url, ['positions' => [['position_id' => $loader->id, 'planned_headcount' => 2]]])->assertSessionHasNoErrors();
        $this->put($url, ['positions' => []])->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('branch_positions')->count());

        // Un usuario limitado a una sede no cambia la estructura
        $scoped = $this->makeUser($this->acme, 'company_admin', ['scope_type' => 'branch', 'scope_branch_id' => $this->agency->id]);
        $this->actingAs($scoped)->put($url, ['positions' => []])->assertForbidden();
        $this->actingAs($scoped)->post('/organization/positions', ['name' => 'X', 'is_roaming' => false, 'is_active' => true])->assertForbidden();
    }

    public function test_employee_form_assigns_a_catalog_position(): void
    {
        $route  = Department::create(['tenant_id' => $this->acme->id, 'name' => 'Operaciones / Ruta']);
        $driver = $this->position('Chofer', ['department_id' => $route->id, 'is_roaming' => true]);
        $this->actingAs($this->admin);

        $this->get('/employees/create')->assertOk()->assertSee('name="position_id"', false)->assertSee('data-roaming="1"', false);

        $this->post('/employees', [
            'first_name' => 'Luis', 'last_name' => 'Ramos', 'email' => 'luis@example.test', 'password' => 'Secret123',
            'role' => 'employee', 'document_type' => 'dni', 'document_number' => '44556677',
            'branch_ids' => [$this->agency->id], 'primary_branch_id' => $this->agency->id,
            'position_id' => $driver->id, 'department_id' => '',
        ])->assertSessionHasNoErrors();

        $luis = User::where('email', 'luis@example.test')->firstOrFail();
        $this->assertSame([$driver->id, 'Chofer', $route->id], [$luis->position_id, $luis->position, $luis->department_id]);

        // Filtro por cargo en el listado
        $this->makeUser($this->acme, 'employee', ['first_name' => 'Otro']);
        $this->get("/employees?position_id={$driver->id}")->assertOk()->assertSee('Luis')->assertDontSee('Otro Employee');

        // Quitar el cargo
        $this->put("/employees/{$luis->id}", [
            'first_name' => 'Luis', 'last_name' => 'Ramos', 'email' => 'luis@example.test',
            'role' => 'employee', 'status' => 'active', 'document_type' => 'dni', 'document_number' => '44556677',
            'branch_ids' => [$this->agency->id], 'primary_branch_id' => $this->agency->id,
            'position_id' => '', 'department_id' => $route->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame([null, null], [$luis->fresh()->position_id, $luis->fresh()->position]);

        // Un cargo de otra empresa no se acepta
        $foreign = DB::table('positions')->insertGetId(['tenant_id' => $this->makeTenant('other')->id, 'name' => 'Ajeno', 'is_active' => true, 'is_roaming' => false]);
        $this->put("/employees/{$luis->id}", [
            'first_name' => 'Luis', 'last_name' => 'Ramos', 'email' => 'luis@example.test',
            'role' => 'employee', 'status' => 'active', 'document_type' => 'dni', 'document_number' => '44556677',
            'branch_ids' => [$this->agency->id], 'primary_branch_id' => $this->agency->id, 'position_id' => $foreign,
        ])->assertSessionHasErrors('position_id');
    }

    public function test_app_receives_the_catalog_and_the_employee_position(): void
    {
        $counter = $this->position('Atención al cliente', ['code' => 'ATC']);
        $this->position('Antiguo', ['is_active' => false]);
        $ana = $this->employeeAt($this->agency, ['position_id' => $counter->id]);

        $this->actingIn($this->admin, $this->acme)->getJson('/api/admin/positions')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Atención al cliente')->assertJsonPath('data.0.code', 'ATC');
        $this->actingIn($this->admin, $this->acme)->getJson('/api/admin/positions?include_inactive=1')->assertJsonCount(2, 'data');

        $this->actingIn($ana, $this->acme)->getJson('/api/admin/positions')->assertForbidden();
        $this->actingIn($ana, $this->acme)->getJson('/api/employee/profile')->assertOk()
            ->assertJsonPath('data.user.position', 'Atención al cliente')
            ->assertJsonPath('data.user.position_id', $counter->id);

        // RRHH asigna el cargo desde la app
        $hr  = $this->makeUser($this->acme, 'hr_manager');
        $bob = $this->employeeAt($this->agency);
        $this->actingIn($hr, $this->acme)->putJson("/api/admin/employees/{$bob->id}", ['position_id' => $counter->id])
            ->assertOk()->assertJsonPath('data.position', 'Atención al cliente')->assertJsonPath('data.position_id', $counter->id);
    }

    public function test_free_text_positions_are_moved_into_the_catalog(): void
    {
        $ana  = $this->employeeAt($this->agency, ['position' => 'Cajera']);
        $beto = $this->employeeAt($this->agency, ['position' => 'cajera']);
        $this->employeeAt($this->agency, ['position' => '  ']);
        DB::table('positions')->delete();
        DB::table('users')->update(['position_id' => null]);

        $migration = require base_path('app/Modules/Core/Database/Migrations/2026_10_18_000001_create_positions_and_branch_staffing.php');
        (fn () => $this->backfillFromFreeText())->call($migration);

        $this->assertSame(['Cajera'], Position::pluck('name')->all());
        $id = Position::value('id');
        $this->assertSame([$id, $id], [$ana->fresh()->position_id, $beto->fresh()->position_id]);
    }
}
