<?php

namespace Tests\Feature;

use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

class ShiftApiTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_hr_creates_fixed_and_rotating_shifts(): void
    {
        $acme = $this->makeTenant('acme');
        $hr   = $this->makeUser($acme, 'hr_manager');

        $this->actingIn($hr, $acme)->postJson('/api/admin/shifts', [
            'name' => 'Noche', 'start_time' => '22:00', 'end_time' => '06:00',
            'working_days' => [1, 2, 3, 4, 5], 'grace_period_minutes' => 15,
        ])->assertCreated()
            ->assertJsonPath('data.type', 'fixed')
            ->assertJsonPath('data.crosses_midnight', true)
            ->assertJsonPath('data.grace_period_minutes', 15);

        $this->actingIn($hr, $acme)->postJson('/api/admin/shifts', [
            'name' => 'Rotativo', 'type' => 'rotating', 'start_time' => '07:00', 'end_time' => '19:00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['rotation_work_days', 'rotation_rest_days']);

        $this->actingIn($hr, $acme)->postJson('/api/admin/shifts', [
            'name' => 'Rotativo', 'type' => 'rotating', 'start_time' => '07:00', 'end_time' => '19:00',
            'rotation_work_days' => 4, 'rotation_rest_days' => 2,
        ])->assertCreated()->assertJsonPath('data.type', 'rotating');

        $this->assertSame(2, Shift::where('tenant_id', $acme->id)->count());
    }

    public function test_shift_in_use_cannot_be_deleted(): void
    {
        $acme   = $this->makeTenant('acme');
        $hr     = $this->makeUser($acme, 'hr_manager');
        $emp    = $this->makeUser($acme);
        $branch = $this->makeBranch($acme);
        $shift  = Shift::create([
            'tenant_id' => $acme->id, 'name' => 'Mañana', 'start_time' => '08:00', 'end_time' => '17:00',
            'working_days' => [1, 2, 3, 4, 5],
        ]);
        EmployeeShift::create([
            'tenant_id' => $acme->id, 'user_id' => $emp->id, 'shift_id' => $shift->id,
            'branch_id' => $branch->id, 'effective_from' => now()->subMonth()->toDateString(), 'is_active' => true,
        ]);

        $this->actingIn($hr, $acme)->deleteJson("/api/admin/shifts/{$shift->id}")
            ->assertStatus(409)->assertJsonPath('code', 'SHIFT_IN_USE');
    }

    public function test_employee_schedule_follows_rotation_cycle(): void
    {
        $acme   = $this->makeTenant('acme');
        $emp    = $this->makeUser($acme);
        $branch = $this->makeBranch($acme);
        $shift  = Shift::create([
            'tenant_id' => $acme->id, 'name' => '4x2', 'type' => 'rotating',
            'start_time' => '07:00', 'end_time' => '19:00', 'working_days' => [],
            'rotation_work_days' => 4, 'rotation_rest_days' => 2,
        ]);
        EmployeeShift::create([
            'tenant_id' => $acme->id, 'user_id' => $emp->id, 'shift_id' => $shift->id,
            'branch_id' => $branch->id, 'effective_from' => '2026-10-01',
            'rotation_anchor_date' => '2026-10-01', 'is_active' => true,
        ]);

        $days = $this->actingIn($emp, $acme)
            ->getJson('/api/schedule?from=2026-10-01&to=2026-10-12')
            ->assertOk()
            ->json('data');

        $this->assertSame(
            [true, true, true, true, false, false, true, true, true, true, false, false],
            array_column($days, 'is_workday')
        );
    }
}
