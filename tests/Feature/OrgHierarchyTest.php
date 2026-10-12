<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Department;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Models\Zone;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Jerarquía Zona → Sede → Área y alcance territorial de los usuarios
 * (toda la empresa, una zona o una sede), más la marcación itinerante.
 */
class OrgHierarchyTest extends TestCase
{
    use RefreshDatabase, CreatesTenants;

    private Tenant $acme;
    private Zone $north;
    private Zone $south;
    private Branch $trujillo;
    private Branch $laredo;
    private Branch $arequipa;
    private User $ana;    // Trujillo
    private User $beto;   // Laredo
    private User $carla;  // Arequipa

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        $this->acme  = $this->makeTenant('acme');
        $this->north = Zone::create(['tenant_id' => $this->acme->id, 'name' => 'Zona Norte', 'code' => 'ZN']);
        $this->south = Zone::create(['tenant_id' => $this->acme->id, 'name' => 'Zona Sur', 'code' => 'ZS']);

        $this->trujillo = $this->makeBranch($this->acme, ['name' => 'Terminal Trujillo', 'zone_id' => $this->north->id, 'branch_type' => 'terminal', 'latitude' => -8.11200, 'longitude' => -79.02900]);
        $this->laredo   = $this->makeBranch($this->acme, ['name' => 'Sede Laredo', 'zone_id' => $this->north->id, 'latitude' => -8.08900, 'longitude' => -78.96000]);
        $this->arequipa = $this->makeBranch($this->acme, ['name' => 'Agencia Arequipa', 'zone_id' => $this->south->id, 'latitude' => -16.40900, 'longitude' => -71.53700]);

        $this->ana   = $this->employeeAt($this->trujillo, 'Ana');
        $this->beto  = $this->employeeAt($this->laredo, 'Beto');
        $this->carla = $this->employeeAt($this->arequipa, 'Carla');
    }

    private function employeeAt(Branch $branch, string $name, array $attrs = []): User
    {
        $user = $this->makeUser($this->acme, 'employee', ['first_name' => $name, 'last_name' => 'Prueba'] + $attrs);
        $user->branches()->attach($branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);

        return $user;
    }

    private function scoped(string $role, array $scope): User
    {
        return $this->makeUser($this->acme, $role, $scope);
    }

    // =========================================================================
    // ESTRUCTURA
    // =========================================================================

    public function test_organization_screen_lists_the_hierarchy_and_manages_zones_and_departments(): void
    {
        $admin = $this->makeUser($this->acme, 'company_admin');
        $ops   = Department::create(['tenant_id' => $this->acme->id, 'name' => 'Operaciones / Ruta']);
        $this->ana->update(['department_id' => $ops->id, 'position' => 'Chofer']);

        $page = $this->actingAs($admin)->get('/organization')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Core/Pages/Organization/Index')
                ->has('zones', 2)->has('branches', 3)->has('departments', 1)
                ->where('canManage', true)->where('scopeLabel', null));

        $trujillo = collect($page->prop('branches'))->firstWhere('name', 'Terminal Trujillo');
        $this->assertSame($this->north->id, $trujillo['zone_id']);
        $this->assertSame('terminal', $trujillo['type']);
        $this->assertSame([['department_id' => $ops->id, 'position_id' => null, 'position' => 'Chofer', 'total' => 1]], $trujillo['staff']);

        // Zonas y áreas: crear, editar, duplicados y eliminar
        $this->post('/organization/zones', ['name' => 'Zona Centro', 'code' => 'ZC', 'is_active' => true])->assertSessionHasNoErrors();
        $this->post('/organization/zones', ['name' => 'Zona Centro', 'code' => 'Z2', 'is_active' => true])->assertSessionHasErrors('name');
        $center = Zone::where('name', 'Zona Centro')->firstOrFail();
        $this->put("/organization/zones/{$center->id}", ['name' => 'Zona Lima', 'code' => 'ZC', 'is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($center->fresh()->is_active);
        $this->post('/organization/departments', ['name' => 'Seguridad', 'code' => null, 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('departments', ['tenant_id' => $this->acme->id, 'name' => 'Seguridad']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'zone.created']);

        // Mover una sede de zona; al borrar la zona, sus sedes quedan sin zona
        $this->patch("/organization/branches/{$this->arequipa->id}", ['zone_id' => $center->id, 'branch_type' => 'warehouse'])->assertSessionHasNoErrors();
        $this->assertSame([$center->id, 'warehouse'], [$this->arequipa->fresh()->zone_id, $this->arequipa->fresh()->branch_type]);
        $this->delete("/organization/zones/{$center->id}")->assertSessionHasNoErrors();
        $this->assertNull($this->arequipa->fresh()->zone_id);

        // Una zona de otra empresa no sirve
        $otherZone = DB::table('zones')->insertGetId(['tenant_id' => $this->makeTenant('other')->id, 'name' => 'Ajena', 'is_active' => true]);
        $this->patch("/organization/branches/{$this->arequipa->id}", ['zone_id' => $otherZone, 'branch_type' => 'agency'])->assertSessionHasErrors('zone_id');

        // Sin permiso de sucursales no se entra
        $this->actingAs($this->makeUser($this->acme, 'supervisor'))->get('/organization')->assertForbidden();
    }

    public function test_composite_indexes_exist(): void
    {
        $names = fn (string $table) => array_column(DB::getSchemaBuilder()->getIndexes($table), 'name');

        $this->assertContains('branches_tenant_zone_idx', $names('branches'));
        $this->assertContains('users_tenant_scope_idx', $names('users'));
        $this->assertContains('users_tenant_department_idx', $names('users'));
    }

    // =========================================================================
    // ALCANCE (RBAC POR TERRITORIO)
    // =========================================================================

    public function test_zone_scoped_supervisor_sees_only_the_branches_and_people_of_the_zone(): void
    {
        $zonal = $this->scoped('supervisor', ['scope_type' => 'zone', 'scope_zone_id' => $this->north->id]);
        Attendance::create(['tenant_id' => $this->acme->id, 'user_id' => $this->carla->id, 'branch_id' => $this->arequipa->id, 'work_date' => now()->toDateString(), 'status' => 'present', 'clock_in_at' => now()]);
        LeaveRequest::create(['tenant_id' => $this->acme->id, 'user_id' => $this->carla->id, 'type' => 'vacation', 'start_date' => '2026-11-01', 'end_date' => '2026-11-02', 'days' => 2, 'status' => 'pending']);
        LeaveRequest::create(['tenant_id' => $this->acme->id, 'user_id' => $this->ana->id, 'type' => 'vacation', 'start_date' => '2026-11-01', 'end_date' => '2026-11-02', 'days' => 2, 'status' => 'pending']);

        $this->actingAs($zonal);
        $this->assertEqualsCanonicalizing(['Terminal Trujillo', 'Sede Laredo'], Branch::pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Ana', 'Beto', $zonal->first_name], User::pluck('first_name')->all());
        $this->assertSame(0, Attendance::count());
        Attendance::create(['tenant_id' => $this->acme->id, 'user_id' => $this->ana->id, 'branch_id' => $this->trujillo->id, 'work_date' => now()->toDateString(), 'status' => 'present', 'clock_in_at' => now()]);
        $this->assertSame([$this->ana->id], Attendance::pluck('user_id')->all());
        $this->assertSame([$this->ana->id], LeaveRequest::pluck('user_id')->all());
        $this->assertNull(User::find($this->carla->id));

        // Tablero en vivo de hoy (lista a quien tiene turno o marcación): su zona, no toda la empresa
        $rows = collect($this->getJson('/live/data')->assertOk()->json('rows'))->pluck('name')->implode(' ');
        $this->assertStringContainsString('Ana', $rows);
        $this->assertStringNotContainsString('Carla', $rows);

        // Aprobaciones: solo las de su zona
        $this->get('/approvals')->assertOk()->assertSee('Ana')->assertDontSee('Carla');

        // Mover una sede a otra zona se refleja al instante (subconsulta, no lista en caché)
        Branch::withoutGlobalScopes()->whereKey($this->laredo->id)->update(['zone_id' => $this->south->id]);
        $this->assertSame(['Terminal Trujillo'], Branch::pluck('name')->all());
    }

    public function test_branch_scoped_admin_is_confined_to_the_branch_in_panel_and_api(): void
    {
        $agency = $this->scoped('hr_manager', ['scope_type' => 'branch', 'scope_branch_id' => $this->trujillo->id]);
        $global = $this->makeUser($this->acme, 'hr_manager');
        $shift  = Shift::create(['tenant_id' => $this->acme->id, 'name' => 'L-V', 'start_time' => '08:00', 'end_time' => '17:00', 'working_days' => [1, 2, 3, 4, 5]]);

        // Panel
        $this->actingAs($agency)->get('/employees')->assertOk()->assertSee('Ana')->assertDontSee('Beto')->assertDontSee('Carla');
        $this->get("/employees/{$this->carla->id}/edit")->assertNotFound();
        $this->get("/branches/{$this->arequipa->id}/edit")->assertNotFound();
        $page = $this->get('/organization')->assertOk();
        $this->assertSame(['Terminal Trujillo'], array_column($page->prop('branches'), 'name'));
        $this->assertFalse($page->prop('canManage'));
        $this->assertSame('Sede: Terminal Trujillo', $page->prop('scopeLabel'));
        $this->post('/organization/zones', ['name' => 'Nueva', 'is_active' => true])->assertForbidden();
        $this->patch("/organization/branches/{$this->trujillo->id}", ['zone_id' => $this->south->id, 'branch_type' => 'agency'])->assertForbidden();

        // Turnos: asigna dentro de su sede; las listas del formulario solo traen lo suyo
        $shifts = $this->get('/shifts')->assertOk();
        $this->assertSame(['Terminal Trujillo'], array_column($shifts->prop('branches'), 'name'));
        $this->assertSame(['Ana'], array_column($shifts->prop('employees'), 'first_name'));
        $this->post('/shifts/assign', ['shift_id' => $shift->id, 'branch_id' => $this->trujillo->id, 'user_ids' => [$this->ana->id], 'effective_from' => '2026-10-01'])
            ->assertSessionHas('success');

        // API de la app: misma frontera
        $names = collect($this->actingIn($agency, $this->acme)->getJson('/api/admin/employees')->assertOk()->json('data'))->pluck('first_name');
        $this->assertTrue($names->contains('Ana'));
        $this->assertFalse($names->contains('Beto'));
        $this->getJson("/api/admin/employees/{$this->carla->id}")->assertNotFound();

        // Un RRHH con alcance de toda la empresa sigue viendo todo
        $this->app['auth']->forgetGuards();
        $this->actingAs($global)->get('/employees')->assertOk()->assertSee('Ana')->assertSee('Beto')->assertSee('Carla');
    }

    public function test_scoped_user_cannot_write_outside_the_scope(): void
    {
        $agency = $this->scoped('hr_manager', ['scope_type' => 'branch', 'scope_branch_id' => $this->trujillo->id]);
        $shift  = Shift::create(['tenant_id' => $this->acme->id, 'name' => 'L-V', 'start_time' => '08:00', 'end_time' => '17:00', 'working_days' => [1, 2, 3, 4, 5]]);
        $this->actingAs($agency);

        // Aunque fuerce los ids en la petición, el modelo no deja guardar fuera del alcance
        foreach ([
            'turno en otra sede'           => fn () => EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $this->ana->id, 'shift_id' => $shift->id, 'branch_id' => $this->arequipa->id, 'effective_from' => '2026-10-01', 'is_active' => true]),
            'turno a otro colaborador'     => fn () => EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $this->carla->id, 'shift_id' => $shift->id, 'branch_id' => $this->trujillo->id, 'effective_from' => '2026-10-01', 'is_active' => true]),
            'asignar a otra sede'          => fn () => $this->ana->branches()->attach($this->arequipa->id, ['is_primary' => false]),
            'crear una sede'               => fn () => $this->makeBranch($this->acme, ['name' => 'Sede pirata']),
            'cambiar su sede de zona'      => fn () => Branch::findOrFail($this->trujillo->id)->update(['zone_id' => $this->south->id]),
        ] as $case => $write) {
            try {
                $write();
                $this->fail("Se permitió: {$case}");
            } catch (\DomainException $e) {
                $this->assertStringContainsString('alcance', $e->getMessage(), $case);
            }
        }
        $this->assertSame(0, EmployeeShift::withoutGlobalScopes()->count());
        $this->assertDatabaseMissing('employee_branch', ['user_id' => $this->ana->id, 'branch_id' => $this->arequipa->id]);

        // Sí puede editar los datos de su propia sede (radio, dirección)
        Branch::findOrFail($this->trujillo->id)->update(['allowed_radius_meters' => 250]);
        $this->assertSame(250, $this->trujillo->fresh()->allowed_radius_meters);

        // Desde el formulario: la sede ajena se rechaza y no queda un usuario a medias
        $response = $this->post('/employees', [
            'first_name' => 'Intruso', 'last_name' => 'Prueba', 'email' => 'intruso@example.test', 'password' => 'Secret123',
            'role' => 'employee', 'document_type' => 'dni', 'document_number' => '70000001',
            'branch_ids' => [$this->arequipa->id], 'primary_branch_id' => $this->arequipa->id,
        ]);
        $this->assertSame(500, $response->status(), 'La barrera del modelo corta la petición');
        $this->assertDatabaseMissing('users', ['email' => 'intruso@example.test']);
    }

    public function test_scope_assignment_rules_on_the_employee_form(): void
    {
        $admin  = $this->makeUser($this->acme, 'company_admin');
        $agency = $this->scoped('company_admin', ['scope_type' => 'branch', 'scope_branch_id' => $this->trujillo->id]);
        $ops    = Department::create(['tenant_id' => $this->acme->id, 'name' => 'Operaciones / Ruta']);
        $base   = fn (string $email, string $role) => [
            'first_name' => 'Nuevo', 'last_name' => 'Mando', 'email' => $email, 'password' => 'Secret123', 'role' => $role,
            'document_type' => 'dni', 'document_number' => (string) random_int(10000000, 99999999),
            'branch_ids' => [$this->trujillo->id], 'primary_branch_id' => $this->trujillo->id,
        ];

        // Alcance de toda la empresa: crea un supervisor zonal, con su área y marcación itinerante
        $this->actingAs($admin)->get('/employees/create')->assertOk()->assertSee('name="scope_type"', false)->assertSee('Marcación itinerante');
        $this->post('/employees', $base('zonal@example.test', 'supervisor') + [
            'scope_type' => 'zone', 'scope_zone_id' => $this->north->id, 'department_id' => $ops->id, 'roaming_attendance_enabled' => '1',
        ])->assertSessionHasNoErrors();
        $zonal = User::where('email', 'zonal@example.test')->firstOrFail();
        $this->assertSame(['zone', $this->north->id, null, $ops->id, true], [$zonal->scope_type, $zonal->scope_zone_id, $zonal->scope_branch_id, $zonal->department_id, $zonal->roaming_attendance_enabled]);
        $this->assertSame('Zona: Zona Norte', $zonal->scopeLabel());

        // La zona es obligatoria con ese alcance; un colaborador nunca lleva alcance
        $this->post('/employees', $base('sinzona@example.test', 'supervisor') + ['scope_type' => 'zone'])->assertSessionHasErrors('scope_zone_id');
        $this->post('/employees', $base('colab@example.test', 'employee') + ['scope_type' => 'branch', 'scope_branch_id' => $this->trujillo->id])->assertSessionHasNoErrors();
        $this->assertSame('global', User::where('email', 'colab@example.test')->value('scope_type'));

        // Un administrador de agencia no puede dar más alcance que el suyo
        $this->actingAs($agency)->get('/employees/create')->assertOk()->assertDontSee('name="scope_type"', false)->assertSee('Sede: Terminal Trujillo');
        $this->post('/employees', $base('rrhh@example.test', 'hr_manager') + ['scope_type' => 'global'])->assertSessionHasNoErrors();
        $created = User::withoutGlobalScopes()->where('email', 'rrhh@example.test')->firstOrFail();
        $this->assertSame(['branch', $this->trujillo->id], [$created->scope_type, $created->scope_branch_id]);
    }

    public function test_scoped_admin_editing_an_employee_keeps_assignments_outside_the_scope(): void
    {
        $agency = $this->scoped('hr_manager', ['scope_type' => 'branch', 'scope_branch_id' => $this->trujillo->id]);
        // Ana también trabaja en Arequipa, que el administrador de Trujillo no ve
        $this->ana->branches()->attach($this->arequipa->id, ['is_primary' => false, 'assigned_from' => '2020-01-01']);

        $this->actingAs($agency)->put("/employees/{$this->ana->id}", [
            'first_name' => 'Ana María', 'last_name' => 'Prueba', 'email' => $this->ana->email, 'role' => 'employee', 'status' => 'active',
            'document_type' => 'dni', 'document_number' => '70000002',
            'branch_ids' => [$this->trujillo->id], 'primary_branch_id' => $this->trujillo->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Ana María', $this->ana->fresh()->first_name);
        $this->assertDatabaseHas('employee_branch', ['user_id' => $this->ana->id, 'branch_id' => $this->arequipa->id]);
        $this->assertDatabaseHas('employee_branch', ['user_id' => $this->ana->id, 'branch_id' => $this->trujillo->id, 'is_primary' => true]);
    }

    // =========================================================================
    // MARCACIÓN ITINERANTE
    // =========================================================================

    public function test_roaming_employee_clocks_in_at_any_branch_or_on_route_and_fixed_one_only_at_home(): void
    {
        Storage::fake(config('filesystems.attendance_disk', 'private'));
        $payload = fn (Branch|array $at) => [
            'latitude'  => $at instanceof Branch ? (float) $at->latitude : $at[0],
            'longitude' => $at instanceof Branch ? (float) $at->longitude : $at[1],
            'accuracy_meters' => 12, 'device_id' => 'test-device', 'device_os' => 'android', 'is_mocked' => 0,
            'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
        ];
        $driver = $this->employeeAt($this->trujillo, 'Dario', ['roaming_attendance_enabled' => true]);
        $relief = $this->employeeAt($this->trujillo, 'Elsa', ['roaming_attendance_enabled' => true]);

        // Fijo: fuera de la geocerca de su sede, aunque esté dentro de otra sede de la empresa
        $this->actingIn($this->ana, $this->acme)->post('/api/attendance/clock-in', $payload($this->arequipa))
            ->assertStatus(422)->assertJsonPath('code', 'GEOFENCE_VIOLATION');

        // Itinerante en otra sede: la marcación queda en ESA sede
        $this->actingIn($driver, $this->acme)->post('/api/attendance/clock-in', $payload($this->arequipa))->assertCreated();
        $attendance = Attendance::where('user_id', $driver->id)->firstOrFail();
        $this->assertSame($this->arequipa->id, $attendance->branch_id);
        $this->assertFalse((bool) $attendance->is_suspicious);

        // …y marca la salida en ruta, lejos de toda sede
        $this->actingIn($driver, $this->acme)->post('/api/attendance/clock-out', $payload([-13.50000, -74.00000]))->assertOk();
        $this->assertNotNull($attendance->fresh()->clock_out_at);

        // Itinerante en ruta desde la entrada: queda en su sede base, con sus coordenadas y sin sospecha
        $this->actingIn($relief, $this->acme)->post('/api/attendance/clock-in', $payload([-13.50000, -74.00000]))->assertCreated();
        $onRoute = Attendance::where('user_id', $relief->id)->firstOrFail();
        $this->assertSame($this->trujillo->id, $onRoute->branch_id);
        $this->assertFalse((bool) $onRoute->is_suspicious);
        $this->assertEqualsWithDelta(-13.5, (float) $onRoute->clock_in_latitude, 0.0001);
    }
}
