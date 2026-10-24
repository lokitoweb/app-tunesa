<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TenantContext;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Shift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Control de faltas: programación contra marcaciones, igual que el calendario */
class AbsencesViewTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;

    private User $hr;

    private User $ana;

    private User $luis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->acme = $this->makeTenant('acme');
        $branch = $this->makeBranch($this->acme, ['name' => 'Lima']);
        $this->hr   = $this->makeUser($this->acme, 'hr_manager');
        $this->ana  = $this->makeUser($this->acme, 'employee', ['first_name' => 'Ana', 'last_name' => 'Torres', 'hire_date' => '2020-01-01']);
        $this->luis = $this->makeUser($this->acme, 'employee', ['first_name' => 'Luis', 'last_name' => 'Mendoza', 'hire_date' => '2020-01-01']);
        $shift = Shift::create(['tenant_id' => $this->acme->id, 'name' => 'Oficina', 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_period_minutes' => 10, 'daily_hours' => 8, 'working_days' => [1, 2, 3, 4, 5]]);
        foreach ([$this->ana, $this->luis] as $u) {
            $u->branches()->attach($branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
            EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $u->id, 'shift_id' => $shift->id,
                'branch_id' => $branch->id, 'effective_from' => '2020-01-01', 'is_active' => true]);
        }
        // Luis marcó el lunes 19; Ana no (y nadie corrió el cierre nocturno)
        Attendance::create(['tenant_id' => $this->acme->id, 'user_id' => $this->luis->id, 'branch_id' => $branch->id,
            'work_date' => '2026-10-19', 'clock_in_at' => '2026-10-19 13:00:00', 'clock_out_at' => '2026-10-19 22:00:00', 'status' => 'present']);
        app(TenantContext::class)->clear();
        $this->travelTo(Carbon::parse('2026-10-21 10:00', 'America/Lima')); // miércoles
    }

    public function test_lists_scheduled_days_without_punches_like_the_calendar(): void
    {
        $page = $this->actingAs($this->hr)->get('/absences?from=2026-10-19&to=2026-10-21')->assertOk();

        // Lunes 19 y martes 20 para Ana, martes 20 para Luis; hoy (21) aún puede marcar
        $page->assertViewHas('total', 3)->assertViewHas('people', 2);
        $page->assertSee('Ana Torres')->assertSee('Regularizar');
        $items = collect($page->viewData('absences')->items());
        $this->assertEqualsCanonicalizing(
            ['Ana Torres|2026-10-19', 'Ana Torres|2026-10-20', 'Luis Mendoza|2026-10-20'],
            $items->map(fn ($i) => $i['name'] . '|' . $i['date'])->all(),
        );

        // Mismo número que el calendario del mes
        $calendar = $this->get('/attendance-calendar?month=2026-10')->assertOk();
        $absentInCalendar = collect($calendar->viewData('rows')->items())->sum(fn ($r) => collect($r['cells'])
            ->filter(fn ($c, $d) => $c && $c['state'] === 'absent' && $d >= '2026-10-19' && $d <= '2026-10-21')->count());
        $this->assertSame(3, $absentInCalendar);
    }

    public function test_old_url_redirects_and_filters_work(): void
    {
        $this->actingAs($this->hr)->get('/attendance?status=absent&start_date=2026-10-01&end_date=2026-10-21')
            ->assertRedirect('/absences?from=2026-10-01&to=2026-10-21');

        $this->get('/absences?q=Mendoza&from=2026-10-19&to=2026-10-21')->assertViewHas('total', 1);
        // El menú lleva a la vista propia, sin parámetros en la URL
        $this->get('/absences')->assertOk()->assertSee('Control de faltas')->assertDontSee('selfie');
    }

    public function test_employees_cannot_open_it(): void
    {
        $this->assertContains($this->actingAs($this->ana)->get('/absences')->status(), [302, 403]);
    }
}
