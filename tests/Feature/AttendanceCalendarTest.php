<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\AttendanceCalendarService;
use App\Modules\Attendance\Services\CalendarFilters;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Holiday;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Fase 2 · calendario con semáforo (matriz mensual): estado de cada tipo de
 * día, permisos y feriados, alcance del supervisor, consultas fijas y Excel.
 *
 * Septiembre 2026: el 1 es martes. "Hoy" es el miércoles 16.
 */
class AttendanceCalendarTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;
    private User $emp;
    private User $supervisor;
    private Branch $branch;
    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->travelTo(Carbon::parse('2026-09-16 10:00:00', 'America/Lima'));

        $this->acme       = $this->makeTenant('acme');
        $this->supervisor = $this->makeUser($this->acme, 'supervisor');
        $this->branch     = $this->makeBranch($this->acme, ['name' => 'Trujillo', 'supervisor_id' => $this->supervisor->id]);
        $this->shift      = Shift::create(['tenant_id' => $this->acme->id, 'name' => 'Mañana', 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_period_minutes' => 10, 'working_days' => [1, 2, 3, 4, 5], 'break_allowed' => true, 'break_duration_minutes' => 60]);
        $this->emp = $this->employee('Rosa', 'Quispe', $this->branch);
    }

    private function employee(string $first, string $last, Branch $branch): User
    {
        $user = $this->makeUser($this->acme, 'employee', ['first_name' => $first, 'last_name' => $last, 'employee_code' => strtoupper(substr($last, 0, 3))]);
        $user->branches()->attach($branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $user->id, 'shift_id' => $this->shift->id,
            'branch_id' => $branch->id, 'effective_from' => '2026-01-01', 'is_active' => true]);

        return $user;
    }

    private function day(User $user, string $date, array $attrs): Attendance
    {
        return Attendance::create(array_merge([
            'tenant_id' => $this->acme->id, 'user_id' => $user->id, 'branch_id' => $this->branch->id,
            'work_date' => $date, 'status' => 'present',
        ], $attrs));
    }

    private function cells(?User $viewer = null): array
    {
        $rows = app(AttendanceCalendarService::class)->paginate(new CalendarFilters($this->acme->id, '2026-09', viewer: $viewer));

        return collect($rows->items())->keyBy('user_id')->all();
    }

    public function test_every_kind_of_day_gets_its_state(): void
    {
        $this->day($this->emp, '2026-09-01', ['clock_in_at' => '2026-09-01 07:58:00', 'clock_out_at' => '2026-09-01 17:02:00', 'worked_hours' => 8.07]);
        $this->day($this->emp, '2026-09-02', ['status' => 'late', 'late_minutes' => 12, 'clock_in_at' => '2026-09-02 08:22:00', 'clock_out_at' => '2026-09-02 17:00:00', 'worked_hours' => 7.63]);
        $this->day($this->emp, '2026-09-03', ['clock_in_at' => '2026-09-03 07:59:00']); // sin salida
        $this->day($this->emp, '2026-09-04', ['status' => 'absent']);
        LeaveRequest::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'type' => 'medical_leave',
            'start_date' => '2026-09-07', 'end_date' => '2026-09-07', 'days' => 1, 'status' => 'approved']);
        Holiday::create(['tenant_id' => $this->acme->id, 'date' => '2026-09-08', 'name' => 'Santa Rosa (prueba)']);
        $this->day($this->emp, '2026-09-09', ['status' => 'absent', 'justification_status' => 'approved']);
        $this->day($this->emp, '2026-09-10', ['clock_in_at' => '2026-09-10 07:55:00', 'clock_out_at' => '2026-09-10 17:00:00', 'is_suspicious' => true]);
        $this->day($this->emp, '2026-09-14', ['status' => 'half_day', 'clock_in_at' => '2026-09-14 08:00:00', 'clock_out_at' => '2026-09-14 12:00:00']);

        $row = $this->cells()[$this->emp->id];
        $state = fn (string $d) => $row['cells'][$d]['state'] ?? null;

        $this->assertSame('present', $state('2026-09-01'));
        $this->assertSame(['T', 12, '08:22 am'], [$row['cells']['2026-09-02']['letter'], $row['cells']['2026-09-02']['late_minutes'], $row['cells']['2026-09-02']['clock_in']]);
        $this->assertSame('incomplete', $state('2026-09-03'));
        $this->assertSame('absent', $state('2026-09-04'));
        $this->assertSame('rest', $state('2026-09-05'), 'sábado sin turno');
        $this->assertSame('excused', $state('2026-09-07'), 'permiso aprobado sin marcación');
        $this->assertSame(['rest', 'Santa Rosa (prueba)'], [$state('2026-09-08'), $row['cells']['2026-09-08']['note']]);
        $this->assertSame('excused', $state('2026-09-09'), 'falta con justificación aprobada');
        $this->assertTrue($row['cells']['2026-09-10']['suspicious']);
        $this->assertSame('present', $state('2026-09-10'));
        $this->assertSame('absent', $state('2026-09-11'), 'día laborable pasado sin marcación');
        $this->assertSame('half_day', $state('2026-09-14'));
        $this->assertSame('scheduled', $state('2026-09-16'), 'hoy aún puede marcar');
        $this->assertSame('scheduled', $state('2026-09-30'));
        $this->assertSame('rest', $state('2026-09-19'));

        // Faltas: 4 (registrada), 11 y 15 (laborables sin marcación)
        $this->assertSame(['absent' => 3, 'late' => 1, 'late_minutes' => 12, 'excused' => 2, 'suspicious' => 1],
            array_intersect_key($row['totals'], array_flip(['absent', 'late', 'late_minutes', 'excused', 'suspicious'])));
        $this->assertSame(15.7, $row['totals']['worked_hours']);
    }

    public function test_days_outside_the_contract_or_without_shift_are_blank(): void
    {
        $this->emp->update(['hire_date' => '2026-09-10']);
        $noShift = $this->makeUser($this->acme, 'employee', ['first_name' => 'Sin', 'last_name' => 'Turno']);
        $noShift->branches()->attach($this->branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);

        $rows = $this->cells();
        $this->assertNull($rows[$this->emp->id]['cells']['2026-09-09']);
        $this->assertSame('absent', $rows[$this->emp->id]['cells']['2026-09-10']['state']);
        $this->assertSame([], array_filter($rows[$noShift->id]['cells']));
    }

    public function test_supervisor_sees_only_their_team(): void
    {
        $other = $this->employee('Ajeno', 'Lima', $this->makeBranch($this->acme, ['name' => 'Lima']));

        $this->assertArrayHasKey($other->id, $this->cells());
        $this->assertSame([$this->emp->id], array_keys($this->cells($this->supervisor)));

        $this->actingAs($this->supervisor)->get(route('attendance.calendar', ['month' => '2026-09']))
            ->assertOk()->assertSee('Quispe')->assertDontSee('Ajeno');
        $this->actingAs($this->makeUser($this->acme, 'employee'))->get(route('attendance.calendar'))->assertRedirect();
    }

    public function test_query_count_does_not_grow_with_the_team(): void
    {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(AttendanceCalendarService::class)->paginate(new CalendarFilters($this->acme->id, '2026-09'));
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->day($this->emp, '2026-09-01', ['clock_in_at' => '2026-09-01 07:58:00']);
        $few = $count();

        foreach (range(1, 8) as $i) {
            $u = $this->employee("Persona{$i}", "Apellido{$i}", $this->branch);
            $this->day($u, '2026-09-02', ['status' => 'late', 'late_minutes' => 5, 'clock_in_at' => '2026-09-02 08:15:00']);
            LeaveRequest::create(['tenant_id' => $this->acme->id, 'user_id' => $u->id, 'type' => 'vacation',
                'start_date' => '2026-09-21', 'end_date' => '2026-09-22', 'days' => 2, 'status' => 'approved']);
        }

        $this->assertSame($few, $count());
        $this->assertLessThanOrEqual(9, $few);
    }

    public function test_closed_month_is_cached_until_a_change_in_that_month(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00', 'America/Lima'));
        $state = fn () => $this->cells()[$this->emp->id]['cells']['2026-09-01']['state'];

        $this->assertSame('absent', $state());

        // Un cambio de otro mes no la invalida; uno de septiembre sí
        $this->day($this->emp, '2026-10-01', ['clock_in_at' => '2026-10-01 07:58:00']);
        $this->assertSame('absent', $state());
        $this->day($this->emp, '2026-09-01', ['clock_in_at' => '2026-09-01 07:58:00', 'clock_out_at' => '2026-09-01 17:00:00']);
        $this->assertSame('present', $state());
    }

    public function test_punches_of_the_current_month_do_not_write_the_cache_table(): void
    {
        // Prueba de estrés: estas filas compartidas daban deadlocks con marcaciones simultáneas
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00', 'America/Lima'));
        $this->day($this->emp, '2026-10-05', ['clock_in_at' => '2026-10-05 07:58:00']);
        $this->assertNull(\Illuminate\Support\Facades\Cache::get('attendance-calendar:v:' . $this->acme->id . ':2026-10'));

        $this->day($this->emp, '2026-09-03', ['clock_in_at' => '2026-09-03 07:58:00']);
        $this->assertSame(1, (int) \Illuminate\Support\Facades\Cache::get('attendance-calendar:v:' . $this->acme->id . ':2026-09'));
    }

    public function test_panel_renders_the_matrix_and_exports_excel_with_colors(): void
    {
        $this->day($this->emp, '2026-09-02', ['status' => 'late', 'late_minutes' => 12, 'clock_in_at' => '2026-09-02 08:22:00', 'clock_out_at' => '2026-09-02 17:00:00']);
        $hr = $this->makeUser($this->acme, 'hr_manager');

        $this->actingAs($hr)->get(route('attendance.calendar', ['month' => '2026-09']))->assertOk()
            ->assertSee('Calendario de asistencia')->assertSee('Quispe, Rosa')
            ->assertSee('Tardanza · Entrada 08:22 am · Salida 05:00 pm · 12 min tarde')
            ->assertSee('openEvidence(', false);

        $response = $this->actingAs($hr)->get(route('attendance.calendar.excel', ['month' => '2026-09', 'q' => 'Quispe']))->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $sheet = IOFactory::load($file)->getSheet(0);

        $this->assertSame('Quispe, Rosa', $sheet->getCell('B2')->getValue());
        $this->assertSame('X 2', $sheet->getCell('E1')->getValue());
        $this->assertSame('T', $sheet->getCell('E2')->getValue());
        $this->assertSame(AttendanceCalendarService::FILLS['late'], $sheet->getStyle('E2')->getFill()->getStartColor()->getRGB());
        $this->assertSame('F', $sheet->getCell('D2')->getValue(), 'el martes 1 sin marcación es falta');
    }

    public function test_day_view_lists_expected_and_marked_people_by_shift(): void
    {
        $this->travelTo(Carbon::parse('2026-09-16 09:00:00', 'America/Lima'));
        $tarde = Shift::create(['tenant_id' => $this->acme->id, 'name' => 'Tarde', 'start_time' => '14:00', 'end_time' => '22:00',
            'grace_period_minutes' => 10, 'working_days' => [1, 2, 3, 4, 5]]);
        $late = $this->employee('Luis', 'Alva', $this->branch);
        $missing = $this->employee('Ana', 'Bravo', $this->branch);
        $afternoon = $this->makeUser($this->acme, 'employee', ['first_name' => 'Eva', 'last_name' => 'Cano']);
        $afternoon->branches()->attach($this->branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $afternoon->id, 'shift_id' => $tarde->id,
            'branch_id' => $this->branch->id, 'effective_from' => '2026-01-01', 'is_active' => true]);
        $onLeave = $this->employee('Iris', 'Diaz', $this->branch);
        LeaveRequest::create(['tenant_id' => $this->acme->id, 'user_id' => $onLeave->id, 'type' => 'vacation',
            'start_date' => '2026-09-16', 'end_date' => '2026-09-16', 'days' => 1, 'status' => 'approved']);

        $this->day($this->emp, '2026-09-16', ['clock_in_at' => '2026-09-16 07:58:00', 'clock_in_distance_meters' => 14, 'clock_in_within_geofence' => true]);
        $this->day($late, '2026-09-16', ['status' => 'late', 'late_minutes' => 20, 'clock_in_at' => '2026-09-16 08:30:00',
            'clock_in_distance_meters' => 140, 'clock_in_within_geofence' => false]);
        $hr = $this->makeUser($this->acme, 'hr_manager');

        $response = $this->actingAs($hr)->get(route('attendance.calendar.day', ['date' => '2026-09-16']))->assertOk();
        $counters = $response->viewData('counters');
        $this->assertSame(['expected' => 5, 'marked' => 2, 'late' => 1, 'missing' => 1, 'upcoming' => 1, 'excused' => 1], $counters);
        $this->assertSame(['Mañana', 'Tarde'], $response->viewData('groups')->keys()->all());
        $response->assertSeeInOrder(['Mañana', 'Alva, Luis', '08:30', '140 m', 'Fuera del radio', 'Bravo, Ana', 'No ha llegado'])
            ->assertSee('Por llegar')->assertSee('Vacaciones')
            ->assertSee('}, 60000);', false);

        // Filtro por turno y un día pasado (sin recarga; quien no marcó es "No marcó")
        $this->actingAs($hr)->get(route('attendance.calendar.day', ['date' => '2026-09-16', 'shift_id' => $tarde->id]))
            ->assertOk()->assertSee('Cano, Eva')->assertDontSee('Alva, Luis');
        $this->actingAs($hr)->get(route('attendance.calendar.day', ['date' => '2026-09-15']))
            ->assertOk()->assertSee('No marcó')->assertDontSee('}, 60000);', false);
    }

    public function test_person_calendar_shows_the_month_and_respects_scope(): void
    {
        $this->day($this->emp, '2026-09-02', ['status' => 'late', 'late_minutes' => 12, 'clock_in_at' => '2026-09-02 08:22:00', 'clock_out_at' => '2026-09-02 17:00:00']);
        $other = $this->employee('Ajeno', 'Lima', $this->makeBranch($this->acme, ['name' => 'Lima']));

        $response = $this->actingAs($this->supervisor)->get(route('attendance.calendar.person', ['id' => $this->emp->id, 'month' => '2026-09']))
            ->assertOk()->assertSee('Rosa Quispe')->assertSee('08:22 am – 05:00 pm')->assertSee('12 min tarde');
        $weeks = $response->viewData('weeks');
        $this->assertSame([null, '2026-09-01'], array_slice($weeks[0], 0, 2), 'septiembre 2026 empieza en martes');
        $this->assertSame('2026-09-30', last(last($weeks)));

        $this->actingAs($this->supervisor)->get(route('attendance.calendar.person', $other->id))->assertNotFound();

        // El nombre en la matriz lleva a su calendario
        $this->actingAs($this->supervisor)->get(route('attendance.calendar', ['month' => '2026-09']))
            ->assertSee(route('attendance.calendar.person', ['id' => $this->emp->id, 'month' => '2026-09']), false);
    }
}
