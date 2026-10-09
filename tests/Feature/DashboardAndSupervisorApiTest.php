<?php

namespace Tests\Feature;

use App\Models\Attendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

class DashboardAndSupervisorApiTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_stats_export_and_team_views(): void
    {
        $acme       = $this->makeTenant('acme');
        $other      = $this->makeTenant('other');
        $hr         = $this->makeUser($acme, 'hr_manager');
        $supervisor = $this->makeUser($acme, 'supervisor');
        $mine       = $this->makeUser($acme, 'employee', ['first_name' => 'Ana']);
        $notMine    = $this->makeUser($acme, 'employee', ['first_name' => 'Luis']);
        $branchA    = $this->makeBranch($acme, ['supervisor_id' => $supervisor->id]);
        $branchB    = $this->makeBranch($acme);
        $mine->branches()->attach($branchA->id, ['is_primary' => true]);
        $notMine->branches()->attach($branchB->id, ['is_primary' => true]);

        $today = now('America/Lima')->toDateString();
        foreach ([[$mine, $branchA, 'late', 12], [$notMine, $branchB, 'present', 0]] as [$u, $b, $status, $late]) {
            Attendance::create([
                'tenant_id' => $acme->id, 'user_id' => $u->id, 'branch_id' => $b->id, 'work_date' => $today,
                'clock_in_at' => now(), 'status' => $status, 'late_minutes' => $late,
                'clock_in_within_geofence' => true, 'worked_hours' => 8,
            ]);
        }
        // Ruido de otra empresa: no debe contarse
        $foreignUser = $this->makeUser($other);
        Attendance::create([
            'tenant_id' => $other->id, 'user_id' => $foreignUser->id,
            'branch_id' => $this->makeBranch($other)->id, 'work_date' => $today, 'status' => 'absent',
        ]);

        $this->actingIn($hr, $acme)->getJson('/api/admin/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.records', 2)
            ->assertJsonPath('data.late_minutes', 12)
            ->assertJsonPath('data.by_status.late', 1)
            ->assertJsonPath('data.top_late.0.user_id', $mine->id);

        $csv = $this->actingIn($hr, $acme)
            ->get("/api/admin/reports/attendance/export?from={$today}&to={$today}")
            ->assertOk()
            ->streamedContent();
        $this->assertSame(3, substr_count(trim($csv), "\n") + 1); // cabecera + 2 filas

        $this->actingIn($supervisor, $acme)->getJson('/api/supervisor/attendance/live')
            ->assertOk()
            ->assertJsonPath('summary.team_size', 1)
            ->assertJsonPath('summary.late', 1);

        $this->actingIn($supervisor, $acme)->getJson('/api/supervisor/attendance')
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->actingIn($supervisor, $acme)->getJson('/api/admin/dashboard/stats')->assertForbidden();
    }
}
