<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** QA3-01: Marcaciones y GPS muestra el cargo (o «Sin cargo») en la lista y en el Excel */
class AttendanceListPositionTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function day(Tenant $tenant, User $user, int $branchId): void
    {
        Attendance::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'branch_id' => $branchId,
            'work_date' => '2026-10-15', 'clock_in_at' => '2026-10-15 08:00:00', 'status' => 'present']);
    }

    public function test_list_and_excel_show_the_position_or_sin_cargo(): void
    {
        $this->seedRoles();
        $acme   = $this->makeTenant('acme');
        $branch = $this->makeBranch($acme, ['name' => 'Ag. Trujillo']);
        $hr     = $this->makeUser($acme, 'hr_manager');
        $rosa   = $this->makeUser($acme, 'employee', ['first_name' => 'Rosa', 'employee_code' => 'EMP-001', 'position' => 'SUPERVISOR DE VENTAS']);
        $juan   = $this->makeUser($acme, 'employee', ['first_name' => 'Juan', 'employee_code' => 'EMP-002', 'position' => null]);
        $this->day($acme, $rosa, $branch->id);
        $this->day($acme, $juan, $branch->id);

        $html = $this->actingAs($hr)->get(route('attendance.index', ['start_date' => '2026-10-15', 'end_date' => '2026-10-15']))->assertOk()->getContent();
        $this->assertStringContainsString('SUPERVISOR DE VENTAS · EMP-001 · Ag. Trujillo', $html);
        $this->assertStringContainsString('Sin cargo · EMP-002 · Ag. Trujillo', $html);
        $this->assertStringNotContainsString('null ·', $html);

        $csv = $this->actingAs($hr)->get(route('reports.export.excel', ['start_date' => '2026-10-15', 'end_date' => '2026-10-15']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Cargo', strtok($csv, "\n"));
        $this->assertStringContainsString('SUPERVISOR DE VENTAS', $csv);
        $this->assertStringContainsString('Sin cargo', $csv);

        // Al cambiar el cargo, la lista muestra el nuevo
        $rosa->update(['position' => 'JEFE DE AGENCIA']);
        $this->actingAs($hr)->get(route('attendance.index', ['start_date' => '2026-10-15', 'end_date' => '2026-10-15']))->assertSee('JEFE DE AGENCIA · EMP-001');
    }
}
