<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Marcaciones y GPS: filtro por cargo (catálogo o cargo escrito en fichas antiguas) y estado Falta */
class AttendanceFiltersTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_position_and_absent_filters(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $hr = $this->makeUser($acme, 'hr_manager');
        $branch = $this->makeBranch($acme);
        $chofer = Position::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'name' => 'CHOFER', 'is_active' => true]);
        Position::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'name' => 'AJENO', 'is_active' => true]);

        $people = [
            'catalogo' => $this->makeUser($acme, 'employee', ['first_name' => 'Catálogo', 'position_id' => $chofer->id, 'position' => 'CHOFER']),
            'texto'    => $this->makeUser($acme, 'employee', ['first_name' => 'Texto', 'position_id' => null, 'position' => 'CHOFER']),
            'otro'     => $this->makeUser($acme, 'employee', ['first_name' => 'Otro', 'position' => 'COUNTER']),
            'sin'      => $this->makeUser($acme, 'employee', ['first_name' => 'Sin', 'position_id' => null, 'position' => null]),
        ];
        foreach ($people as $key => $u) {
            Attendance::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'user_id' => $u->id, 'branch_id' => $branch->id,
                'work_date' => '2026-10-15', 'status' => $key === 'otro' ? 'absent' : 'present']);
        }
        $names = fn (array $q) => collect($this->actingAs($hr)->get('/attendance?' . http_build_query($q + ['start_date' => '2026-10-15', 'end_date' => '2026-10-15']))
            ->assertOk()->viewData('attendances')->items())->map(fn ($a) => $a->user->first_name)->sort()->values()->all();

        // El catálogo de la empresa (no el de otra) y "Sin cargo"
        $this->actingAs($hr)->get('/attendance')->assertSee('Todos los cargos')->assertSee('CHOFER')->assertDontSee('AJENO');
        $this->assertSame(['Catálogo', 'Texto'], $names(['position_id' => $chofer->id]));
        $this->assertSame(['Sin'], $names(['position_id' => 'none']));
        $this->assertSame(['Otro'], $names(['status' => 'absent']));
        $this->assertCount(4, $names([]));
    }
}
