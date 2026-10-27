<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
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

        // Cada marcación del equipo dice de quién es
        $this->actingIn($supervisor, $acme)->getJson('/api/supervisor/attendance/live')
            ->assertJsonPath('attendances.0.user.id', $mine->id)
            ->assertJsonPath('attendances.0.user.full_name', $mine->full_name);
        $this->actingIn($supervisor, $acme)->getJson('/api/supervisor/attendance')
            ->assertJsonPath('data.0.user.id', $mine->id);
        $this->actingIn($supervisor, $acme)->getJson('/api/supervisor/team')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);

        // RRHH (ve toda la asistencia) cubre a toda la empresa, sin la otra empresa
        $this->actingIn($hr, $acme)->getJson('/api/supervisor/attendance/live')
            ->assertOk()
            ->assertJsonPath('summary.team_size', 3) // Ana, Luis y el supervisor
            ->assertJsonCount(2, 'attendances');
        $this->actingIn($hr, $acme)->getJson('/api/supervisor/attendance')->assertJsonPath('meta.total', 2);
        $ids = collect($this->actingIn($hr, $acme)->getJson('/api/supervisor/team')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        // Mismo equipo que attendance/live: también el supervisor, que marca
        $this->assertSame(collect([$mine->id, $notMine->id, $supervisor->id])->sort()->values()->all(), $ids);
        $this->assertSame($mine->full_name, collect($this->actingIn($hr, $acme)->getJson('/api/supervisor/team')->json('data'))->firstWhere('id', $mine->id)['full_name']);

        // Las consultas no crecen con el tamaño del equipo (antes ~3 por integrante)
        $queries = function () use ($hr, $acme) {
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            $this->actingIn($hr, $acme)->getJson('/api/supervisor/team')->assertOk();
            \Illuminate\Support\Facades\DB::disableQueryLog();

            return count(\Illuminate\Support\Facades\DB::getQueryLog());
        };
        $few = $queries();
        foreach (range(1, 10) as $i) {
            $this->makeUser($acme, 'employee', ['first_name' => "Extra{$i}"]);
        }
        $this->assertSame($few, $queries());
    }

    public function test_supervisor_team_includes_supervisors_of_its_branches_like_attendance_live(): void
    {
        $acme      = $this->makeTenant('acme');
        $boss      = $this->makeUser($acme, 'supervisor');
        $branch    = $this->makeBranch($acme, ['supervisor_id' => $boss->id]);
        $employee  = $this->makeUser($acme, 'employee');
        $colleague = $this->makeUser($acme, 'supervisor');   // supervisor que marca en la sede de $boss
        $inactive  = $this->makeUser($acme, 'employee', ['status' => 'inactive']);
        $hr        = $this->makeUser($acme, 'hr_manager');   // no marca desde la app
        foreach ([$employee, $colleague, $inactive, $hr, $boss] as $u) {
            $u->branches()->attach($branch->id, ['is_primary' => true]);
        }

        $ids = collect($this->actingIn($boss, $acme)->getJson('/api/supervisor/team')->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();
        $expected = collect([$employee->id, $colleague->id])->sort()->values()->all();
        $this->assertSame($expected, $ids);

        $this->actingIn($boss, $acme)->getJson('/api/supervisor/attendance/live')
            ->assertOk()->assertJsonPath('summary.team_size', 2);
    }
}
