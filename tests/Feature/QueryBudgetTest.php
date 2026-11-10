<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Shift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Rendimiento: las pantallas de asistencia no hacen una consulta por colaborador (N+1).
 * Se mide con 3 y con 15 personas con dos semanas de jornadas; la diferencia debe quedar acotada.
 */
class QueryBudgetTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;

    private Branch $branch;

    private Shift $shift;

    private User $hr;

    /** Consultas extra toleradas al pasar de 3 a 15 personas (no una por persona) */
    private const GROWTH = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->travelTo(Carbon::parse('2026-10-31 18:00', 'America/Lima'));
        $this->acme   = $this->makeTenant('acme');
        $this->branch = $this->makeBranch($this->acme);
        $this->hr     = $this->makeUser($this->acme, 'company_admin');
        $this->shift  = Shift::create(['tenant_id' => $this->acme->id, 'name' => 'Oficina', 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_period_minutes' => 10, 'daily_hours' => 8, 'working_days' => [1, 2, 3, 4, 5]]);
    }

    private function people(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $emp = $this->makeUser($this->acme, 'employee');
            $emp->branches()->attach($this->branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
            EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $emp->id, 'shift_id' => $this->shift->id,
                'branch_id' => $this->branch->id, 'effective_from' => '2020-01-01', 'is_active' => true]);
            foreach (range(1, 14) as $d) {
                $date = sprintf('2026-10-%02d', $d);
                if (Carbon::parse($date)->isWeekend()) {
                    continue;
                }
                Attendance::withoutGlobalScopes()->create(['tenant_id' => $this->acme->id, 'user_id' => $emp->id, 'branch_id' => $this->branch->id,
                    'shift_id' => $this->shift->id, 'work_date' => $date, 'clock_in_at' => "{$date} 08:00:00", 'clock_out_at' => "{$date} 17:00:00",
                    'worked_hours' => 8, 'status' => 'present']);
            }
        }
    }

    private function queries(string $url): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $this->actingAs($this->hr)->get($url)->assertOk();
        DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return $count;
    }

    public static function pages(): array
    {
        return [
            'asistencia'   => ['/attendance?date_from=2026-10-01&date_to=2026-10-31'],
            'calendario'   => ['/attendance-calendar?month=2026-10'],
            'mapa en vivo' => ['/live'],
            'planillas'    => ['/payroll?year=2026&month=10'],
            'colaboradores' => ['/employees'],
        ];
    }

    /** @dataProvider pages */
    public function test_screen_does_not_query_once_per_person(string $url): void
    {
        $this->people(3);
        $few = $this->queries($url);
        $this->people(12);
        $many = $this->queries($url);

        fwrite(STDERR, sprintf("\n  %-45s 3 personas: %3d consultas · 15 personas: %3d", $url, $few, $many));
        $this->assertLessThanOrEqual($few + self::GROWTH, $many, "{$url}: {$few} → {$many} consultas (crece por persona)");
    }
}
