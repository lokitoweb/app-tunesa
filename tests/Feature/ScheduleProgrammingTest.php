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

    public function test_flag_off_keeps_the_previous_behaviour(): void
    {
        $this->program();
        // Sin schedule_v2 la programación se guarda pero no rige: sin asignación semanal, sin programación
        $this->assertSame('unscheduled', app(ScheduleGate::class)->statusFor($this->people['Juan'], '2026-10-05')['reason']);
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
        $post($this->hr, ['reason' => '', 'rows' => [['user_id' => $this->people['Juan']->id, 'days' => []]]])->assertStatus(422)->assertJsonValidationErrors('reason');
        $post($this->hr, ['rows' => [['user_id' => $this->people['Juan']->id, 'days' => ['2026-10-02' => 999999]]]])->assertStatus(422)->assertJsonValidationErrors('rows');

        $supervisor = $this->makeUser($this->acme, 'supervisor');
        $post($supervisor, ['rows' => [['user_id' => $this->people['Juan']->id, 'days' => []]]])->assertForbidden();
        $this->assertSame(0, EmployeeScheduleDay::count());
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
