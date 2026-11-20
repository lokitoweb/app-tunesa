<?php

namespace Tests\Feature;

use App\Modules\Attendance\Services\AttendanceCalendarService;
use App\Modules\Attendance\Services\CalendarFilters;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Holiday;
use App\Modules\HR\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * El calendario guarda en caché los meses cerrados. Un cambio en la plantilla del horario, un feriado,
 * los datos del colaborador o quién está en la lista se ve al instante, sin esperar a que venza la caché.
 */
class CalendarCacheRefreshTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;

    private User $emp;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00', 'America/Lima'));
        $this->acme = $this->makeTenant('acme');
        $branch = $this->makeBranch($this->acme);
        $this->emp = $this->makeUser($this->acme, 'employee', ['first_name' => 'Ana', 'last_name' => 'Ruiz']);
        $this->emp->branches()->attach($branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $this->shift = Shift::create(['tenant_id' => $this->acme->id, 'name' => 'MAÑANA', 'start_time' => '07:00', 'end_time' => '15:00',
            'working_days' => [1, 2, 3, 4, 5, 6], 'day_schedules' => [5 => ['start_time' => '15:00', 'end_time' => '23:00']]]);
        EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'shift_id' => $this->shift->id,
            'branch_id' => $branch->id, 'effective_from' => '2020-01-01', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Fila de Ana en octubre (mes cerrado: va a la caché) */
    private function october(): array
    {
        return app(AttendanceCalendarService::class)->paginate(new CalendarFilters($this->acme->id, '2026-10'))->first();
    }

    public function test_closed_months_show_template_holiday_and_employee_changes_at_once(): void
    {
        // Viernes 2/10 con la hora propia del viernes en la plantilla (15:00); el jueves 1/10, la general
        $this->assertSame('15:00', $this->october()['cells']['2026-10-02']['shift_start']);
        $this->assertSame('07:00', $this->october()['cells']['2026-10-01']['shift_start']);

        // RR. HH. quita la hora propia del viernes: el mes cerrado lo muestra sin esperar
        $this->shift->update(['day_schedules' => null]);
        $this->assertSame('07:00', $this->october()['cells']['2026-10-02']['shift_start']);

        // Feriado nuevo en octubre
        $before = $this->october()['cells']['2026-10-08']['state'];
        Holiday::withoutGlobalScopes()->create(['tenant_id' => $this->acme->id, 'date' => '2026-10-08', 'name' => 'Combate de Angamos']);
        $this->assertNotSame($before, $this->october()['cells']['2026-10-08']['state']);

        // Datos del colaborador que muestra el calendario
        $this->emp->update(['last_name' => 'Ruiz Paredes']);
        $this->assertSame('Ruiz Paredes', $this->october()['last_name']);

        // Alguien nuevo en la lista: la página no reusa las filas guardadas de otra lista
        $nuevo = $this->makeUser($this->acme, 'employee', ['first_name' => 'Beto', 'last_name' => 'Alva']);
        $nuevo->branches()->attach($this->emp->branches()->first()->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $page = app(AttendanceCalendarService::class)->paginate(new CalendarFilters($this->acme->id, '2026-10'));
        $this->assertSame(['Alva', 'Ruiz Paredes'], collect($page->items())->pluck('last_name')->all());
    }

    public function test_unrelated_saves_do_not_renew_the_cache(): void
    {
        $this->october();
        $general = Cache::get('attendance-calendar:g:' . $this->acme->id);

        // Último acceso u otros datos que el calendario no muestra: la caché sigue sirviendo
        $this->emp->forceFill(['last_login_at' => now()])->save();
        $this->assertSame($general, Cache::get('attendance-calendar:g:' . $this->acme->id));
    }
}
