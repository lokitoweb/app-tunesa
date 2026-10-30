<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Notifications\AttendanceOutsideSchedule;
use Illuminate\Support\Facades\Notification;
use App\Modules\Attendance\Services\AttendanceCalendarService;
use App\Modules\Attendance\Services\CalendarFilters;
use App\Modules\Attendance\Services\Reports\AttendanceReportService;
use App\Modules\Attendance\Services\Reports\ReportFilters;
use App\Modules\Attendance\Services\ScheduleGate;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\EmployeeScheduleDay;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Shift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * QA Ronda 2 · HQ-01 / HQ-05 / CP-06 / CP-07: programación por calendario.
 *
 * Datos del Anexo A.2 del informe (ejemplo ilustrativo, Ag. Trujillo, dos semanas):
 *   Juan:  A A A A A · · | B B B B B · ·
 *   Fidel: B B B B B · · | A A A A A · ·
 *   Juana: A A A · · B B | A A · · B B B
 * A = 08:00-15:00, B = 14:00-21:00, "·" = en blanco (no labora). Lunes 5 a domingo 18/10/2026.
 */
class ScheduleProgrammingTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const PATTERN = [
        'Juan'  => 'AAAAA--BBBBB--',
        'Fidel' => 'BBBBB--AAAAA--',
        'Juana' => 'AAA--BBAA--BBB',
    ];

    private Tenant $acme;
    private Branch $trujillo;
    private User $hr;
    /** @var array<string, User> */
    private array $people = [];
    private Shift $a;
    private Shift $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('public');
        Storage::fake('private');
        $this->travelTo(Carbon::parse('2026-10-01 10:00', 'America/Lima'));

        $this->acme = $this->makeTenant('acme');
        $this->hr = $this->makeUser($this->acme, 'hr_manager');
        $this->trujillo = $this->makeBranch($this->acme, ['name' => 'Ag. Trujillo']);
        foreach (array_keys(self::PATTERN) as $name) {
            $this->people[$name] = $this->makeUser($this->acme, 'employee', ['first_name' => $name, 'last_name' => 'Prueba']);
            $this->people[$name]->branches()->attach($this->trujillo->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        }
        $this->a = Shift::create(['tenant_id' => $this->acme->id, 'name' => 'Horario A', 'type' => 'fixed', 'start_time' => '08:00', 'end_time' => '15:00', 'working_days' => [1, 2, 3, 4, 5], 'color' => '#3C729E']);
        $this->b = Shift::create(['tenant_id' => $this->acme->id, 'name' => 'Horario B', 'type' => 'fixed', 'start_time' => '14:00', 'end_time' => '21:00', 'working_days' => [1, 2, 3, 4, 5], 'color' => '#2E9E8C']);
    }

    /** Guarda las dos semanas del anexo desde el calendario (los días en blanco van como libres) */
    private function program(): void
    {
        $rows = [];
        foreach (self::PATTERN as $name => $pattern) {
            $days = [];
            foreach (str_split($pattern) as $i => $code) {
                $days[Carbon::parse('2026-10-05')->addDays($i)->toDateString()] = ['A' => $this->a->id, 'B' => $this->b->id][$code] ?? null;
            }
            $rows[] = ['user_id' => $this->people[$name]->id, 'days' => $days];
        }

        $this->actingAs($this->hr)->postJson(route('shifts.schedule.save'), [
            'branch_id' => $this->trujillo->id, 'from' => '2026-10-05', 'to' => '2026-10-18',
            'reason' => 'Programación quincena Ag. Trujillo', 'rows' => $rows,
        ])->assertOk()->assertJsonPath('result.created', 42);
    }

    private function flag(string $state): void
    {
        $this->artisan('attendance:flag', ['tenant' => 'acme', 'flag' => 'schedule_v2', 'state' => $state, '--reason' => 'Prueba de programación'])->assertSuccessful();
    }

    public function test_screen_lists_the_agency_and_saving_writes_every_day_with_audit(): void
    {
        $this->actingAs($this->hr)->get(route('shifts.schedule', ['branch_id' => $this->trujillo->id, 'month' => '2026-10']))->assertOk();
        $json = $this->actingAs($this->hr)->getJson(route('shifts.schedule', ['branch_id' => $this->trujillo->id, 'month' => '2026-10']))->assertOk()->json();
        $this->assertCount(3, $json['people']);
        $this->assertSame('none', $json['grid'][$this->people['Juan']->id]['2026-10-05']['source']);

        $this->program();

        // 3 personas × 14 días; los días en blanco quedan como día libre explícito
        $this->assertSame(42, EmployeeScheduleDay::count());
        $this->assertSame(12, EmployeeScheduleDay::whereNull('shift_id')->count()); // 4 días en blanco por persona
        $this->assertNull(EmployeeScheduleDay::where('user_id', $this->people['Juan']->id)->where('work_date', '2026-10-10')->sole()->branch_id);
        $log = AuditLog::where('action', 'schedule.programmed')->sole();
        $this->assertSame([42, 'Programación quincena Ag. Trujillo'], [$log->meta['context']['changed_days'], $log->meta['context']['reason']]);

        // Pantalla: lo guardado vuelve como programado
        $json = $this->actingAs($this->hr)->getJson(route('shifts.schedule', ['branch_id' => $this->trujillo->id, 'month' => '2026-10']))->json();
        $this->assertSame(['shift_id' => $this->b->id, 'source' => 'programmed'], $json['grid'][$this->people['Juan']->id]['2026-10-12']);

        // Guardar lo mismo otra vez no cambia nada ni ensucia la bitácora
        $this->program_again_is_idempotent();
    }

    /** QA3-03: la pantalla trae el catálogo de cargos y el cargo de cada persona; repetir para los visibles no toca a los ocultos */
    public function test_position_filter_data_and_bulk_copy_only_touches_the_people_sent(): void
    {
        $call = \App\Modules\Core\Models\Position::create(['tenant_id' => $this->acme->id, 'name' => 'CALL CENTER', 'is_active' => true]);
        $old  = \App\Modules\Core\Models\Position::create(['tenant_id' => $this->acme->id, 'name' => 'CARGO ANTIGUO', 'is_active' => false]);
        \App\Modules\Core\Models\Position::create(['tenant_id' => $this->acme->id, 'name' => 'INACTIVO SIN NADIE', 'is_active' => false]);
        $this->people['Juan']->update(['position_id' => $call->id]);
        $this->people['Fidel']->update(['position_id' => $old->id]);

        $json = $this->actingAs($this->hr)->getJson(route('shifts.schedule', ['branch_id' => $this->trujillo->id, 'month' => '2026-10']))->assertOk()->json();
        $this->assertSame(['CALL CENTER', 'CARGO ANTIGUO'], array_column($json['positions'], 'name'));
        $byName = collect($json['people'])->keyBy(fn ($p) => explode(', ', $p['name'])[1]);
        $this->assertSame([$call->id, 'CALL CENTER'], [$byName['Juan']['position_id'], $byName['Juan']['position']]);
        $this->assertNull($byName['Juana']['position_id']);

        // Con el filtro «CALL CENTER» la pantalla manda solo a Juan: Fidel y Juana quedan como estaban
        $this->program();
        $this->actingAs($this->hr)->postJson(route('shifts.schedule.repeat'), [
            'branch_id' => $this->trujillo->id, 'user_ids' => [$this->people['Juan']->id],
            'source_from' => '2026-10-05', 'source_to' => '2026-10-11', 'target_from' => '2026-10-19', 'until' => '2026-10-25',
        ])->assertOk();
        $this->assertSame(7, EmployeeScheduleDay::where('user_id', $this->people['Juan']->id)->whereBetween('work_date', ['2026-10-19', '2026-10-25'])->count());
        $this->assertSame(0, EmployeeScheduleDay::whereIn('user_id', [$this->people['Fidel']->id, $this->people['Juana']->id])->whereBetween('work_date', ['2026-10-19', '2026-10-25'])->count());
    }

    private function program_again_is_idempotent(): void
    {
        $juan = $this->people['Juan'];
        $days = EmployeeScheduleDay::where('user_id', $juan->id)->get()->mapWithKeys(fn ($d) => [$d->work_date->toDateString() => $d->shift_id])->all();
        $this->actingAs($this->hr)->postJson(route('shifts.schedule.save'), [
            'branch_id' => $this->trujillo->id, 'from' => '2026-10-05', 'to' => '2026-10-18', 'reason' => 'Sin cambios',
            'rows' => [['user_id' => $juan->id, 'days' => $days]],
        ])->assertOk()->assertJsonPath('result.unchanged', 14);
        $this->assertSame(1, AuditLog::where('action', 'schedule.programmed')->count());
    }

    public function test_approved_vacation_days_are_locked_in_the_matrix_and_reports(): void
    {
        $juan = $this->people['Juan'];
        \App\Modules\HR\Models\LeaveRequest::create(['tenant_id' => $this->acme->id, 'user_id' => $juan->id, 'type' => 'vacation',
            'start_date' => '2026-10-07', 'end_date' => '2026-10-08', 'days' => 2, 'status' => 'approved']);

        $url = route('shifts.schedule', ['branch_id' => $this->trujillo->id, 'month' => '2026-10']);
        $this->assertSame('vacation', $this->actingAs($this->hr)->getJson($url)->json("grid.{$juan->id}.2026-10-07.source"));

        // Aunque el cliente los envíe, los días de vacaciones no se sobrescriben
        $this->postJson(route('shifts.schedule.save'), [
            'branch_id' => $this->trujillo->id, 'from' => '2026-10-05', 'to' => '2026-10-09', 'reason' => 'Semana',
            'rows' => [['user_id' => $juan->id, 'days' => array_fill_keys(['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'], $this->a->id)]],
        ])->assertOk()->assertJsonPath('result.locked', 2)->assertJsonPath('result.created', 3);
        $this->assertFalse(EmployeeScheduleDay::where('user_id', $juan->id)->whereIn('work_date', ['2026-10-07', '2026-10-08'])->exists());

        $row = collect(app(AttendanceCalendarService::class)->paginate(new CalendarFilters($this->acme->id, '2026-10', viewer: $this->hr))->items())
            ->firstWhere('user_id', $juan->id);
        $this->assertSame(['excused', 'Vacaciones'], [$row['cells']['2026-10-07']['state'], $row['cells']['2026-10-07']['label']]);
    }

    public function test_shift_colors_are_unique_per_agency(): void
    {
        $lima = $this->makeBranch($this->acme, ['name' => 'Ag. Lima']);
        $piura = $this->makeBranch($this->acme, ['name' => 'Ag. Piura']); // nueva: sin horarios
        $this->a->update(['branch_id' => $this->trujillo->id]); // #3C729E
        $this->b->update(['branch_id' => $lima->id]);           // #2E9E8C
        $new = fn (int $branchId, string $color, string $name = 'Nuevo') => $this->actingAs($this->hr)->post('/shifts', [
            'branch_id' => $branchId, 'name' => $name, 'color' => $color, 'type' => 'fixed', 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_period_minutes' => 10, 'break_duration_minutes' => 60, 'daily_hours' => 8, 'working_days' => [1]]);

        // En la misma agencia, no (sin importar mayúsculas); en otra agencia o una nueva, sí
        $new($this->trujillo->id, '#3c729e')->assertSessionHasErrors('color');
        $new($lima->id, '#3C729E', 'Lima A')->assertSessionHasNoErrors();
        $new($piura->id, '#2E9E8C', 'Piura B')->assertSessionHasNoErrors();
        $this->assertSame('#2E9E8C', Shift::where('name', 'Piura B')->value('color'));

        // Un horario antiguo sin agencia se ofrece en todas: su color queda tomado en todas
        Shift::create(['tenant_id' => $this->acme->id, 'name' => 'General', 'type' => 'fixed', 'start_time' => '08:00', 'end_time' => '17:00', 'working_days' => [1], 'color' => '#111111']);
        foreach ([$this->trujillo, $lima, $piura] as $branch) {
            $new($branch->id, '#111111', "X {$branch->id}")->assertSessionHasErrors('color');
        }

        // Editar: conservar su color sí; tomar el de otro horario de su agencia no; el de otra agencia sí
        $edit = fn (string $color) => $this->put("/shifts/{$this->a->id}", ['name' => 'Horario A', 'color' => $color, 'branch_id' => $this->trujillo->id,
            'type' => 'fixed', 'start_time' => '08:00', 'end_time' => '15:00', 'grace_period_minutes' => 10, 'break_duration_minutes' => 60, 'daily_hours' => 7, 'working_days' => [1, 2, 3, 4, 5]]);
        $edit('#3C729E')->assertSessionHasNoErrors();
        $new($this->trujillo->id, '#AAAAAA', 'Trujillo 2')->assertSessionHasNoErrors();
        $edit('#AAAAAA')->assertSessionHasErrors('color');
        $edit('#2E9E8C')->assertSessionHasNoErrors(); // lo usa Lima, no Trujillo

        // La pantalla recibe agencia y color de cada horario para bloquear por agencia
        $colors = collect($this->get('/shifts/create')->prop('colorShifts'));
        $this->assertEquals(['color' => '#2E9E8C', 'branch_id' => $piura->id], collect($colors->firstWhere('name', 'Piura B'))->only(['color', 'branch_id'])->all());

        // Otra empresa puede usar el mismo color en su agencia
        app(\App\Modules\Core\Services\TenantContext::class)->clear();
        $other = $this->makeTenant('other');
        $otherHr = $this->makeUser($other, 'hr_manager');
        $otherBranch = $this->makeBranch($other);
        $this->actingAs($otherHr)->post('/shifts', ['branch_id' => $otherBranch->id, 'name' => 'Otra', 'color' => '#3C729E', 'type' => 'fixed', 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_period_minutes' => 10, 'break_duration_minutes' => 60, 'daily_hours' => 8, 'working_days' => [1]])->assertSessionHasNoErrors();
    }

    public function test_programmed_days_rule_automatically_in_the_app_without_activating_anything(): void
    {
        $juan = $this->people['Juan'];
        $this->program(); // Juan (sin asignación semanal): A del 5 al 9, B del 12 al 16, fines de semana libres
        $this->assertFalse((bool) $this->acme->fresh()->setting('flags.schedule_v2')); // nada que activar
        $appDay = function (string $date) use ($juan) {
            app(\App\Modules\Core\Services\TenantContext::class)->clear();

            return $this->actingIn($juan, $this->acme)->getJson("/api/schedule?from={$date}&to={$date}")->assertOk()->json('data.0');
        };

        // La app (inicio y "Mis turnos") muestra lo programado de cada día
        $this->assertSame(['Horario B', '14:00', '21:00'], [$appDay('2026-10-12')['shift']['name'], $appDay('2026-10-12')['shift']['start_time'], $appDay('2026-10-12')['shift']['end_time']]);
        $this->assertSame('Horario A', $appDay('2026-10-05')['shift']['name']);
        $this->assertFalse($appDay('2026-10-10')['is_workday']); // sábado marcado como libre

        // Ese día la marcación está habilitada y se mide contra el horario programado (B, 14:00)
        $this->travelTo(Carbon::parse('2026-10-12 14:20', 'America/Lima'));
        app(\App\Modules\Core\Services\TenantContext::class)->clear();
        $today = $this->actingIn($juan, $this->acme)->getJson('/api/attendance/today')->assertOk();
        $this->assertTrue($today->json('schedule.can_clock_in'));
        $this->assertSame('Horario B', $today->json('schedule.shift.name'));
        $this->assertNotSame('NOT_SCHEDULED', $today->json('next_action.code'));
    }

    public function test_saving_only_writes_the_marked_days_and_copy_only_repeats_programmed_days(): void
    {
        $juan = $this->people['Juan'];
        $fidel = $this->people['Fidel'];
        // Fidel tiene asignación semanal A de lunes a viernes; Juan no
        EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $fidel->id, 'shift_id' => $this->a->id,
            'branch_id' => $this->trujillo->id, 'effective_from' => '2026-01-01', 'is_active' => true]);

        // Guardar solo dos días de Juan en octubre: el resto del mes no se toca
        $this->actingAs($this->hr)->postJson(route('shifts.schedule.save'), [
            'branch_id' => $this->trujillo->id, 'from' => '2026-10-01', 'to' => '2026-10-31',
            'rows' => [['user_id' => $juan->id, 'days' => ['2026-10-26' => $this->b->id, '2026-10-27' => null]]],
        ])->assertOk()->assertJsonPath('result.created', 2);
        $this->assertSame(2, EmployeeScheduleDay::count());
        $this->assertNull(EmployeeScheduleDay::where('work_date', '2026-10-27')->sole()->shift_id); // libre a propósito

        // Copiar la semana del 26 al 1 de noviembre: copia esos dos días programados, no la asignación semanal de Fidel
        $this->postJson(route('shifts.schedule.repeat'), [
            'branch_id' => $this->trujillo->id, 'source_from' => '2026-10-26', 'source_to' => '2026-11-01',
            'target_from' => '2026-11-02', 'until' => '2026-11-08',
        ])->assertOk()->assertJsonPath('result.created', 2);
        $this->assertSame([$this->b->id, null], [
            EmployeeScheduleDay::where('user_id', $juan->id)->where('work_date', '2026-11-02')->value('shift_id'),
            EmployeeScheduleDay::where('user_id', $juan->id)->where('work_date', '2026-11-03')->sole()->shift_id,
        ]);
        $this->assertFalse(EmployeeScheduleDay::where('user_id', $fidel->id)->exists(), 'la asignación semanal no se duplica como programación');

        // Fidel sigue con su asignación semanal en la app
        app(\App\Modules\Core\Services\TenantContext::class)->clear();
        $this->assertSame('Horario A', $this->actingIn($fidel, $this->acme)->getJson('/api/schedule?from=2026-11-02&to=2026-11-02')->json('data.0.shift.name'));
    }

    public function test_reprogramming_a_closed_month_refreshes_the_cached_calendar(): void
    {
        $juan = $this->people['Juan'];
        $this->travelTo(Carbon::parse('2026-11-20 10:00', 'America/Lima')); // octubre ya cerrado (se cachea)
        $cell = fn () => collect(app(AttendanceCalendarService::class)->paginate(new CalendarFilters($this->acme->id, '2026-10', viewer: $this->hr))->items())
            ->firstWhere('user_id', $juan->id)['cells']['2026-10-14'];
        $this->assertNull($cell()); // sin programación ni asignación

        $this->actingAs($this->hr)->postJson(route('shifts.schedule.save'), [
            'branch_id' => $this->trujillo->id, 'from' => '2026-10-01', 'to' => '2026-10-31',
            'rows' => [['user_id' => $juan->id, 'days' => ['2026-10-14' => $this->a->id]]],
        ])->assertOk();
        $this->assertSame(['absent', 'Horario A'], [$cell()['state'], $cell()['shift']]);
    }

    public function test_shifts_belong_to_an_agency_and_the_matrix_only_offers_its_own(): void
    {
        $lima = $this->makeBranch($this->acme, ['name' => 'Ag. Lima']);
        $this->a->update(['branch_id' => $this->trujillo->id]);
        $this->b->update(['branch_id' => $lima->id]);
        $general = Shift::create(['tenant_id' => $this->acme->id, 'name' => 'General', 'type' => 'fixed', 'start_time' => '08:00', 'end_time' => '17:00', 'working_days' => [1], 'color' => '#111111']);

        $ids = array_column($this->actingAs($this->hr)->getJson(route('shifts.schedule', ['branch_id' => $this->trujillo->id, 'month' => '2026-10']))->json('shifts'), 'id');
        $this->assertEqualsCanonicalizing([$this->a->id, $general->id], $ids);

        $this->post('/shifts', ['name' => 'Sin agencia', 'type' => 'fixed', 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_period_minutes' => 10, 'break_duration_minutes' => 60, 'daily_hours' => 8, 'working_days' => [1], 'color' => '#0284C7'])
            ->assertSessionHasErrors('branch_id');
    }

    public function test_cp06_each_day_is_evaluated_with_its_own_schedule(): void
    {
        $this->program();
        $this->flag('on');
        $this->travelTo(Carbon::parse('2026-10-20 12:00', 'America/Lima'));
        $gate = app(ScheduleGate::class);

        // Juan: A la primera semana, B la segunda, sábado libre (descanso, no "sin programación")
        $this->assertSame('Horario A', $gate->statusFor($this->people['Juan'], '2026-10-05')['shift']['name']);
        $this->assertSame(['Horario B', '14:00'], [$gate->statusFor($this->people['Juan'], '2026-10-12')['shift']['name'], $gate->statusFor($this->people['Juan'], '2026-10-12')['shift']['start_time']]);
        $this->assertSame('rest_day', $gate->statusFor($this->people['Juan'], '2026-10-10')['reason']);
        // Juana trabaja B un sábado aunque el turno B sea de lunes a viernes: manda la programación
        $this->assertSame('Horario B', $gate->statusFor($this->people['Juana'], '2026-10-10')['shift']['name']);

        // Calendario y reporte respetan lo programado (sin marcaciones: faltas en días con horario)
        $row = collect(app(AttendanceCalendarService::class)->paginate(new CalendarFilters($this->acme->id, '2026-10', viewer: $this->hr))->items())
            ->firstWhere('user_id', $this->people['Juana']->id);
        $this->assertSame(['absent', 'Horario B'], [$row['cells']['2026-10-10']['state'], $row['cells']['2026-10-10']['shift']]);
        $this->assertSame('rest', $row['cells']['2026-10-08']['state']);
        $this->assertNull($row['cells']['2026-10-01']); // antes de lo programado y sin asignación: sin programación

        $report = collect(iterator_to_array(app(AttendanceReportService::class)->each(new ReportFilters($this->acme->id, '2026-10-05', '2026-10-18', viewer: $this->hr)), false))
            ->keyBy('user_id');
        $this->assertSame(10, $report[$this->people['Juan']->id]['summary']['scheduled_days']);
        $this->assertSame(10, $report[$this->people['Juana']->id]['summary']['scheduled_days']);

        // Control de faltas: Fidel el lunes 5 falta con el horario B de ese día y en la agencia programada
        $this->artisan('attendance:mark-absences', ['--date' => '2026-10-05'])->assertSuccessful();
        $fidel = Attendance::withoutGlobalScopes()->where('user_id', $this->people['Fidel']->id)->sole();
        $this->assertSame([$this->b->id, $this->trujillo->id, 'absent'], [$fidel->shift_id, $fidel->branch_id, $fidel->status]);
        $this->assertSame(3, Attendance::withoutGlobalScopes()->count());
    }

    public function test_cp07_punch_before_the_day_schedule_is_compared_with_that_day(): void
    {
        $this->program();
        $this->flag('on');
        $juan = $this->people['Juan'];

        // Lunes 12 le toca B (14:00): marca 13:55 → sin tardanza y dentro de su horario
        $this->travelTo(Carbon::parse('2026-10-12 13:55', 'America/Lima'));
        $this->actingIn($juan, $this->acme)->post('/api/attendance/clock-in', $this->punch())->assertCreated();
        $day = Attendance::withoutGlobalScopes()->where('user_id', $juan->id)->sole();
        $this->assertSame([$this->b->id, 0, 'present'], [$day->shift_id, (int) $day->late_minutes, $day->status]);
        $this->assertNotContains('OUTSIDE_SCHEDULE', $day->clock_in_meta['suspicion_reasons'] ?? []);
    }

    public function test_hq02_punch_without_any_schedule_is_outside_programming_and_alerts_the_supervisor(): void
    {
        Notification::fake();
        $supervisor = $this->makeUser($this->acme, 'supervisor');
        $this->trujillo->update(['supervisor_id' => $supervisor->id]);
        $nuevo = $this->makeUser($this->acme, 'employee', ['first_name' => 'Sin', 'last_name' => 'Horario']);
        $nuevo->branches()->attach($this->trujillo->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $reasons = fn () => Attendance::withoutGlobalScopes()->where('user_id', $nuevo->id)->sole()->clock_in_meta['suspicion_reasons'] ?? [];

        // schedule_v2: fuera de programación y aviso a la jefatura de la agencia
        $this->flag('on');
        $this->travelTo(Carbon::parse('2026-10-06 09:00', 'America/Lima'));
        $this->actingIn($nuevo, $this->acme)->post('/api/attendance/clock-in', $this->punch())->assertCreated();
        $this->assertContains('OUTSIDE_SCHEDULE', $reasons());
        Notification::assertSentTo($supervisor, AttendanceOutsideSchedule::class,
            fn (AttendanceOutsideSchedule $n) => str_contains($n->body(), 'fuera de programación (no tenía horario ese día)'));
    }

    public function test_hq02_flag_off_punch_without_assignments_is_not_flagged_as_before(): void
    {
        $nuevo = $this->makeUser($this->acme, 'employee');
        $nuevo->branches()->attach($this->trujillo->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $this->travelTo(Carbon::parse('2026-10-05 09:00', 'America/Lima'));
        $this->actingIn($nuevo, $this->acme)->post('/api/attendance/clock-in', $this->punch())->assertCreated();
        $this->assertNotContains('OUTSIDE_SCHEDULE', Attendance::withoutGlobalScopes()->where('user_id', $nuevo->id)->sole()->clock_in_meta['suspicion_reasons'] ?? []);
    }

    public function test_without_the_flag_programmed_days_rule_and_the_rest_keeps_the_weekly_assignment(): void
    {
        $this->program();
        $gate = app(ScheduleGate::class);
        // Sin schedule_v2 (por defecto): el día programado rige igual; el día libre programado es descanso
        $this->assertSame('Horario A', $gate->statusFor($this->people['Juan'], '2026-10-05')['shift']['name']);
        $this->assertSame('rest_day', $gate->statusFor($this->people['Juan'], '2026-10-10')['reason']);
        // Un día sin programar, sin asignación semanal: como antes, sin programación
        $this->assertSame('unscheduled', $gate->statusFor($this->people['Juan'], '2026-10-25')['reason']);

        // Otra agencia que no programa: su asignación semanal sigue exactamente igual
        $lima = $this->makeBranch($this->acme, ['name' => 'Ag. Lima']);
        $rosa = $this->makeUser($this->acme, 'employee', ['first_name' => 'Rosa']);
        $rosa->branches()->attach($lima->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $rosa->id, 'shift_id' => $this->b->id, 'branch_id' => $lima->id,
            'effective_from' => '2026-01-01', 'is_active' => true]);
        $this->assertSame('Horario B', $gate->statusFor($rosa, '2026-10-05')['shift']['name']);
        $this->assertSame('rest_day', $gate->statusFor($rosa, '2026-10-10')['reason']); // sábado: B es lun-vie
    }

    public function test_copy_and_repeat_weeks_until_a_date(): void
    {
        $this->program();
        $juan = $this->people['Juan'];

        // Repetir las dos semanas del anexo (5-18) desde el 19 hasta el 1 de noviembre, solo para Juan
        $this->actingAs($this->hr)->postJson(route('shifts.schedule.repeat'), [
            'branch_id' => $this->trujillo->id, 'user_ids' => [$juan->id], 'source_from' => '2026-10-05', 'source_to' => '2026-10-18',
            'target_from' => '2026-10-19', 'until' => '2026-11-01', 'reason' => 'Repetir quincena',
        ])->assertOk()->assertJsonPath('result.created', 14);
        $shiftOn = fn (string $d) => EmployeeScheduleDay::where('user_id', $juan->id)->where('work_date', $d)->value('shift_id');
        $this->assertSame([$this->a->id, null, $this->b->id], [$shiftOn('2026-10-19'), $shiftOn('2026-10-24'), $shiftOn('2026-10-26')]);
        $this->assertSame(0, EmployeeScheduleDay::where('user_id', $this->people['Fidel']->id)->where('work_date', '>', '2026-10-18')->count());

        // Copiar semana: la semana 1 (5-11) a la del 2 al 8 de noviembre, para todos
        $this->actingAs($this->hr)->postJson(route('shifts.schedule.repeat'), [
            'branch_id' => $this->trujillo->id, 'source_from' => '2026-10-05', 'source_to' => '2026-10-11',
            'target_from' => '2026-11-02', 'until' => '2026-11-08', 'reason' => 'Copiar semana 1',
        ])->assertOk()->assertJsonPath('result.created', 21);
        $this->assertSame($this->b->id, EmployeeScheduleDay::where('user_id', $this->people['Juana']->id)->where('work_date', '2026-11-07')->value('shift_id'));
    }

    public function test_validation_and_permissions(): void
    {
        $other = $this->makeUser($this->acme, 'employee');
        $other->branches()->attach($this->makeBranch($this->acme)->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $post = fn (User $as, array $body) => $this->actingAs($as)->postJson(route('shifts.schedule.save'), $body + [
            'branch_id' => $this->trujillo->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'reason' => 'Programación de octubre',
        ]);

        $post($this->hr, ['rows' => [['user_id' => $other->id, 'days' => []]]])->assertStatus(422)->assertJsonValidationErrors('rows');
        $post($this->hr, ['to' => '2026-12-31', 'rows' => [['user_id' => $this->people['Juan']->id, 'days' => []]]])->assertStatus(422)->assertJsonValidationErrors('to');
        // El motivo es opcional: sin él la bitácora queda con uno genérico
        $post($this->hr, ['reason' => '', 'rows' => [['user_id' => $this->people['Juan']->id, 'days' => ['2026-10-05' => $this->a->id]]]])->assertOk();
        $this->assertSame('Programación de horarios', AuditLog::where('action', 'schedule.programmed')->latest('id')->first()->meta['context']['reason']);
        EmployeeScheduleDay::query()->delete(); // el resto del test parte sin programación
        $post($this->hr, ['reason' => str_repeat('x', 301), 'rows' => [['user_id' => $this->people['Juan']->id, 'days' => []]]])->assertStatus(422)->assertJsonValidationErrors('reason');
        $post($this->hr, ['rows' => [['user_id' => $this->people['Juan']->id, 'days' => ['2026-10-02' => 999999]]]])->assertStatus(422)->assertJsonValidationErrors('rows');

        // Regla 6: un supervisor programa, pero solo sus agencias: esta no es suya
        $supervisor = $this->makeUser($this->acme, 'supervisor');
        $post($supervisor, ['rows' => [['user_id' => $this->people['Juan']->id, 'days' => []]]])->assertNotFound();
        // Contabilidad no programa
        $post($this->makeUser($this->acme, 'accountant'), ['rows' => [['user_id' => $this->people['Juan']->id, 'days' => []]]])->assertForbidden();
        $this->assertSame(0, EmployeeScheduleDay::count());
    }

    public function test_rule6_agency_supervisor_programs_only_his_agency_and_team(): void
    {
        $supervisor = $this->makeUser($this->acme, 'supervisor');
        $this->trujillo->update(['supervisor_id' => $supervisor->id]);
        $lima = $this->makeBranch($this->acme, ['name' => 'Ag. Lima']);
        $ajeno = $this->makeUser($this->acme, 'employee', ['first_name' => 'Ajeno']);
        $ajeno->branches()->attach($lima->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);

        // Solo ve su agencia y su equipo, con el plazo de 5 días antes del mes
        $json = $this->actingAs($supervisor)->getJson(route('shifts.schedule', ['branch_id' => $this->trujillo->id, 'month' => '2026-11']))->assertOk()->json();
        $this->assertCount(3, $json['people']);
        $this->assertSame('2026-10-27', $json['deadline']);
        $this->actingAs($supervisor)->get(route('shifts.schedule'))->assertOk()->assertDontSee('Ag. Lima');

        // Programa su equipo; no la agencia ajena ni a alguien de otro equipo
        $this->actingAs($supervisor)->postJson(route('shifts.schedule.save'), [
            'branch_id' => $this->trujillo->id, 'from' => '2026-11-02', 'to' => '2026-11-02', 'reason' => 'Programación de noviembre',
            'rows' => [['user_id' => $this->people['Juan']->id, 'days' => ['2026-11-02' => $this->a->id]]],
        ])->assertOk()->assertJsonPath('result.created', 1);
        $this->actingAs($supervisor)->postJson(route('shifts.schedule.save'), [
            'branch_id' => $lima->id, 'from' => '2026-11-02', 'to' => '2026-11-02', 'reason' => 'Agencia ajena',
            'rows' => [['user_id' => $ajeno->id, 'days' => []]],
        ])->assertNotFound();
        $this->actingAs($supervisor)->postJson(route('shifts.schedule.save'), [
            'branch_id' => $this->trujillo->id, 'from' => '2026-11-02', 'to' => '2026-11-02', 'reason' => 'Colaborador ajeno',
            'rows' => [['user_id' => $ajeno->id, 'days' => []]],
        ])->assertStatus(422);

        // Programar no le da crear ni editar turnos, ni la asignación semanal
        $this->actingAs($supervisor)->get(route('shifts.index'))->assertForbidden();
        $this->actingAs($supervisor)->post(route('shifts.assign'), [])->assertForbidden();
        $this->assertSame(1, EmployeeScheduleDay::count());
    }

    public function test_rule11_supervisor_authorizes_days_without_schedule_only_for_his_team(): void
    {
        $supervisor = $this->makeUser($this->acme, 'supervisor');
        $this->trujillo->update(['supervisor_id' => $supervisor->id]);
        $ajeno = $this->makeUser($this->acme, 'employee', ['first_name' => 'Ajeno']);
        $ajeno->branches()->attach($this->makeBranch($this->acme)->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $authorize = fn (User $who) => $this->actingAs($supervisor)->post('/attendance-offshift', ['user_id' => $who->id, 'work_date' => '2026-10-03', 'reason' => 'Inventario de sábado']);

        $authorize($this->people['Juan'])->assertSessionHasNoErrors()->assertRedirect('/attendance-offshift');
        $authorize($ajeno)->assertForbidden();
        $authorize($supervisor)->assertForbidden();
        $this->assertSame(1, \App\Modules\Attendance\Models\OffShiftAuthorization::count());

        // No ve ni retira autorizaciones de otro equipo
        $otra = \App\Modules\Attendance\Models\OffShiftAuthorization::create(['tenant_id' => $this->acme->id, 'user_id' => $ajeno->id, 'work_date' => '2026-10-03', 'reason' => 'De RR. HH.', 'authorized_by' => $this->hr->id]);
        $this->actingAs($supervisor)->get('/attendance-offshift')->assertOk()->assertSee('Juan')->assertDontSee('Ajeno');
        $this->actingAs($supervisor)->delete("/attendance-offshift/{$otra->id}")->assertForbidden();
        $this->assertModelExists($otra);
    }

    public function test_security_no_cross_tenant_shift_and_bounded_writes(): void
    {
        $globex = $this->makeTenant('globex');
        $foreign = Shift::create(['tenant_id' => $globex->id, 'name' => 'Ajeno', 'type' => 'fixed', 'start_time' => '08:00', 'end_time' => '15:00', 'working_days' => [1]]);
        $body = ['branch_id' => $this->trujillo->id, 'from' => '2026-10-05', 'to' => '2026-10-05', 'reason' => 'Prueba de aislamiento',
            'rows' => [['user_id' => $this->people['Juan']->id, 'days' => ['2026-10-05' => $foreign->id]]]];

        // Super admin sin empresa elegida: el panel le pide elegir una (no opera "entre empresas")
        $this->actingAs($this->makeUser(null, 'super_admin'))->postJson(route('shifts.schedule.save'), $body)->assertStatus(409);
        // RR. HH. de una empresa no puede usar un turno de otra
        $this->actingAs($this->hr)->postJson(route('shifts.schedule.save'), $body)->assertStatus(422)->assertJsonValidationErrors('rows');
        $this->assertSame(0, EmployeeScheduleDay::count());

        // Una repetición que escribiría más de MAX_REPEAT_CELLS días se rechaza antes de escribir (110 personas × 366 días)
        foreach (range(1, 107) as $i) {
            $this->makeUser($this->acme, 'employee', ['first_name' => "Extra{$i}"])->branches()->attach($this->trujillo->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        }
        $this->actingAs($this->hr)->postJson(route('shifts.schedule.repeat'), [
            'branch_id' => $this->trujillo->id, 'source_from' => '2026-10-05', 'source_to' => '2026-10-11',
            'target_from' => '2026-10-12', 'until' => '2027-10-12', 'reason' => 'Repetir un año',
        ])->assertStatus(422)->assertJsonValidationErrors('until');
        $this->assertSame(0, EmployeeScheduleDay::count());
    }

    public function test_a_shift_programmed_in_the_future_cannot_be_deleted(): void
    {
        $this->program(); // horario B programado del 5 al 16 de octubre (hoy es 1 de octubre)

        $this->actingAs($this->hr)->from(route('shifts.index'))->delete(route('shifts.destroy', $this->b->id))->assertSessionHas('error');
        $this->actingIn($this->hr, $this->acme)->deleteJson("/api/admin/shifts/{$this->b->id}")->assertStatus(409)->assertJsonPath('code', 'SHIFT_IN_USE');
        $this->assertNotSoftDeleted($this->b);

        // Un turno sin asignaciones ni días programados sí se elimina
        $libre = Shift::create(['tenant_id' => $this->acme->id, 'name' => 'Libre', 'type' => 'fixed', 'start_time' => '09:00', 'end_time' => '13:00', 'working_days' => [1]]);
        $this->actingAs($this->hr)->from(route('shifts.index'))->delete(route('shifts.destroy', $libre->id))->assertSessionHas('success');
        $this->assertSoftDeleted($libre);
    }

    public function test_new_assignment_overlapping_a_current_one_is_blocked(): void
    {
        $juan = $this->people['Juan'];
        EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $juan->id, 'shift_id' => $this->a->id, 'branch_id' => $this->trujillo->id, 'effective_from' => '2026-01-01', 'is_active' => true]);
        $sat = Shift::create(['tenant_id' => $this->acme->id, 'name' => 'Sábado', 'type' => 'fixed', 'start_time' => '09:00', 'end_time' => '13:00', 'working_days' => [6]]);
        $assign = fn (Shift $s) => $this->actingAs($this->hr)->from(route('shifts.index'))->post(route('shifts.assign'), [
            'shift_id' => $s->id, 'branch_id' => $this->trujillo->id, 'user_ids' => [$juan->id], 'effective_from' => '2026-10-01', 'replace_current' => 0,
        ]);

        $assign($this->b)->assertSessionHasErrors('user_ids');
        $this->assertSame(1, EmployeeShift::where('user_id', $juan->id)->count());
        $assign($sat)->assertSessionHasNoErrors();
        $this->assertSame(2, EmployeeShift::where('user_id', $juan->id)->count());
    }

    private function punch(): array
    {
        return [
            'latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12,
            'device_id' => 'test-device', 'device_os' => 'android', 'is_mocked' => 0,
            'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
        ];
    }
}
