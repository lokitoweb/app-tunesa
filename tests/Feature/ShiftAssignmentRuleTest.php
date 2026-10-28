<?php

namespace Tests\Feature;

use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** EmployeeShift::assign: una sola regla para el panel, Horarios y la API */
class ShiftAssignmentRuleTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_api_assignment_closes_the_previous_shift_the_day_before_without_overlap(): void
    {
        $this->seedRoles();
        $this->travelTo(Carbon::parse('2026-10-09 10:00:00', 'America/Lima'));
        $acme = $this->makeTenant('acme');
        $hr = $this->makeUser($acme, 'hr_manager');
        $ana = $this->makeUser($acme);
        $branch = $this->makeBranch($acme);
        $make = fn (string $name) => Shift::create(['tenant_id' => $acme->id, 'name' => $name, 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_period_minutes' => 10, 'daily_hours' => 8, 'working_days' => [1, 2, 3, 4, 5]]);
        [$day, $night] = [$make('Día'), $make('Noche')];
        $old = EmployeeShift::create(['tenant_id' => $acme->id, 'user_id' => $ana->id, 'shift_id' => $day->id,
            'branch_id' => $branch->id, 'effective_from' => '2026-01-01', 'is_active' => true]);

        $this->actingIn($hr, $acme)->postJson("/api/admin/employees/{$ana->id}/assign-shift", [
            'shift_id' => $night->id, 'branch_id' => $branch->id, 'effective_from' => '2026-10-09',
        ])->assertSuccessful();

        // Antes la anterior quedaba hasta hoy: dos turnos el mismo día. Ahora termina ayer
        $old->refresh();
        $this->assertFalse($old->is_active);
        $this->assertSame('2026-10-08', $old->effective_until->toDateString());

        $new = EmployeeShift::withoutGlobalScopes()->where('shift_id', $night->id)->sole();
        $this->assertSame(['2026-10-09', '2026-10-09'], [$new->effective_from->toDateString(), $new->rotation_anchor_date->toDateString()]);
        $this->assertSame(1, EmployeeShift::withoutGlobalScopes()->where('user_id', $ana->id)->current('2026-10-09')->count());
    }
}
