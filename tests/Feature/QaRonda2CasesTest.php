<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\AttendanceCalendarService;
use App\Modules\Attendance\Services\CalendarFilters;
use App\Modules\Attendance\Services\Reports\AttendanceReportService;
use App\Modules\Attendance\Services\Reports\ReportFilters;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TenantContext;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\Shift;
use App\Modules\HR\Services\ScheduleProgramming;
use App\Modules\Payroll\Services\PayrollExportService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * QA Ronda 2 · casos CP-01 a CP-09 (sección 5.5 del informe) con datos equivalentes:
 * agencia "Ag. Trujillo", horario A 08:00-15:00 (valores por defecto: tolerancia 10 min,
 * break 60 min), octubre de 2026, y los colaboradores de ejemplo del informe.
 * La empresa usa las dos banderas (schedule_v2 y hours_v2), como quedaría tras activarlas.
 * CP-06 y CP-07 están en ScheduleProgrammingTest (A/B por día y marcación antes del horario).
 */
class QaRonda2CasesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $tunesa;
    private Branch $trujillo;
    private User $hr;
    private Shift $a;
    /** @var array<string, User> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('public');
        Storage::fake('private');
        $this->travelTo(Carbon::parse('2026-09-30 18:00', 'America/Lima'));

        $this->tunesa = $this->makeTenant('tunesa', ['company_name' => 'Tunesa expres']);
        $this->hr = $this->makeUser($this->tunesa, 'hr_manager');
        $this->trujillo = $this->makeBranch($this->tunesa, ['name' => 'Ag. Trujillo']);
        $this->a = Shift::create(['tenant_id' => $this->tunesa->id, 'name' => 'Horario A', 'type' => 'fixed', 'start_time' => '08:00', 'end_time' => '15:00', 'working_days' => [1, 2, 3, 4, 5]]);

        foreach (['Juan' => ['Perez', '01234567'], 'Fidel' => ['Alvarez', '40000001'], 'Juana' => ['Pereda', '40000002'], 'Yohana' => ['Contreras', '40000003']] as $first => [$last, $dni]) {
            $u = $this->makeUser($this->tunesa, 'employee', ['first_name' => $first, 'last_name' => $last, 'position' => 'COUNTER', 'document_type' => 'dni', 'document_number' => $dni]);
            $u->branches()->attach($this->trujillo->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
            $this->p[$first] = $u;
        }

        foreach (['schedule_v2', 'hours_v2'] as $flag) {
            $this->artisan('attendance:flag', ['tenant' => 'tunesa', 'flag' => $flag, 'state' => 'on', '--reason' => 'Casos de QA Ronda 2'])->assertSuccessful();
        }

        // Programación de octubre por calendario: horario A de lunes a viernes, fines de semana en blanco
        $values = [];
        foreach ($this->p as $u) {
            for ($d = CarbonImmutable::parse('2026-10-01'); $d->month === 10; $d = $d->addDay()) {
                $values[$u->id][$d->toDateString()] = $d->isWeekend() ? null : $this->a->id;
            }
        }
        app(ScheduleProgramming::class)->saveRange($this->hr, $this->trujillo, array_keys($values), '2026-10-01', '2026-10-31', $values, 'Programación de octubre');
    }

    private function punch(User $u, string $at, string $type): void
    {
        $this->travelTo(Carbon::parse($at, 'America/Lima'));
        $this->actingIn($u, $this->tunesa)->post("/api/attendance/{$type}", [
            'latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12,
            'device_id' => 'test-device', 'device_os' => 'android', 'is_mocked' => 0,
            'photo' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
        ])->assertSuccessful();
    }

    private function report(string $from, string $to): array
    {
        return collect(iterator_to_array(app(AttendanceReportService::class)->each(new ReportFilters($this->tunesa->id, $from, $to, viewer: $this->hr)), false))
            ->keyBy('user_id')->all();
    }

    private function day(array $report, User $u, string $date): array
    {
        return collect($report[$u->id]['days'])->firstWhere('date', $date);
    }

    public function test_cp01_tolerance_status_and_worked_hours_formula(): void
    {
        $juan = $this->p['Juan'];
        $this->punch($juan, '2026-10-01 08:06', 'clock-in');
        $this->punch($juan, '2026-10-01 15:03', 'clock-out');
        $this->travelTo(Carbon::parse('2026-11-02 09:00', 'America/Lima'));

        $saved = Attendance::withoutGlobalScopes()->where('user_id', $juan->id)->sole();
        $day = $this->day($this->report('2026-10-01', '2026-10-01'), $juan, '2026-10-01');

        // Dentro de la tolerancia (10 min): sin tardanza y estado Normal, igual en la jornada y en el reporte
        $this->assertSame([0, 'present'], [(int) $saved->late_minutes, $saved->status]);
        $this->assertSame([0, 'normal', 'Normal'], [$day['late_minutes'], $day['day_status'], $day['day_status_label']]);
        // 6:57 marcadas − 60 min de break del turno = 5:57, igual en la jornada guardada y en el reporte (fórmula única)
        $this->assertSame([357, '5:57'], [(int) round((float) $saved->worked_hours * 60), AttendanceReportService::hm($day['worked_minutes'])]);
        $this->assertSame(['08:00', '08:06 am', '15:00', '03:03 pm'], [$day['scheduled_in'], $day['clock_in'], $day['scheduled_out'], $day['clock_out']]);
        // Si la empresa paga el refrigerio: 6:57 (el valor "antes del break" del informe)
        $this->assertSame(417, app(AttendanceReportService::class)->calculate($saved, $this->a, true)['worked_minutes']);
        $this->assertStringContainsString('Horas trabajadas = salida marcada − entrada marcada', app(AttendanceReportService::class)->rules($this->tunesa->id)['formula']);
    }

    public function test_cp02_scheduled_without_punches_is_an_absence(): void
    {
        $this->travelTo(Carbon::parse('2026-10-02 02:00', 'America/Lima'));
        $this->artisan('attendance:mark-absences', ['--date' => '2026-10-01'])->assertSuccessful();
        $this->travelTo(Carbon::parse('2026-11-02 09:00', 'America/Lima'));

        $report = $this->report('2026-10-01', '2026-10-01');
        $day = $this->day($report, $this->p['Fidel'], '2026-10-01');
        $this->assertSame(['absent', 'Faltó', '0:00'], [$day['day_status'], $day['day_status_label'], AttendanceReportService::hm($day['worked_minutes'])]);
        $this->assertSame(1, $report[$this->p['Fidel']->id]['summary']['absences_unjustified']);
        $this->assertSame('absent', Attendance::withoutGlobalScopes()->where('user_id', $this->p['Fidel']->id)->sole()->status);
    }

    public function test_cp03_day_off_is_rest_and_not_an_absence(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 09:00', 'America/Lima'));
        $report = $this->report('2026-10-03', '2026-10-03');
        $day = $this->day($report, $this->p['Juan'], '2026-10-03');

        $this->assertSame(['rest', 'Descanso'], [$day['day_status'], $day['day_status_label']]);
        $this->assertSame([0, 1], [$report[$this->p['Juan']->id]['summary']['absences_unjustified'], $report[$this->p['Juan']->id]['summary']['rest_days']]);
        $cell = collect(app(AttendanceCalendarService::class)->paginate(new CalendarFilters($this->tunesa->id, '2026-10', viewer: $this->hr))->items())
            ->firstWhere('user_id', $this->p['Juan']->id)['cells']['2026-10-03'];
        $this->assertSame(['rest', 'Descanso'], [$cell['state'], $cell['label']]);
    }

    public function test_cp04_approved_vacation_is_vacation_not_absence_and_days_used_match_payroll(): void
    {
        $juana = $this->p['Juana'];
        LeaveRequest::create(['tenant_id' => $this->tunesa->id, 'user_id' => $juana->id, 'type' => 'vacation',
            'start_date' => '2026-10-01', 'end_date' => '2026-10-04', 'days' => 4, 'status' => 'approved']);
        $this->travelTo(Carbon::parse('2026-11-02 09:00', 'America/Lima'));

        $report = $this->report('2026-10-01', '2026-10-31');
        $day = $this->day($report, $juana, '2026-10-02');
        $this->assertSame(['vacation', 'Vacaciones'], [$day['day_status'], $day['day_status_label']]);
        $this->assertSame(0, collect($report[$juana->id]['days'])->whereBetween('date', ['2026-10-01', '2026-10-04'])->where('status', 'absent')->count());
        // Días de vacaciones usados: 4 (calendario), mismo número que el cruce con planilla
        app(TenantContext::class)->set($this->tunesa);
        $payroll = app(PayrollExportService::class)->rows(2026, 10)->firstWhere('document_number', '40000002');
        $this->assertSame([4, 4], [$report[$juana->id]['summary']['vacation_days'], (int) $payroll['vacation_days']]);
        $cell = collect(app(AttendanceCalendarService::class)->paginate(new CalendarFilters($this->tunesa->id, '2026-10', viewer: $this->hr))->items())
            ->firstWhere('user_id', $juana->id)['cells']['2026-10-02'];
        $this->assertSame(['excused', 'V', 'Vacaciones'], [$cell['state'], $cell['letter'], $cell['label']]);
        // Saldo de vacaciones: bloqueado por regla pendiente de RR. HH. (pregunta 9)
    }

    public function test_cp05_open_day_is_incomplete_and_hr_corrects_it_with_audit(): void
    {
        $yohana = $this->p['Yohana'];
        $this->punch($yohana, '2026-10-01 07:58', 'clock-in');
        $this->travelTo(Carbon::parse('2026-10-05 09:00', 'America/Lima'));

        $day = $this->day($this->report('2026-10-01', '2026-10-01'), $yohana, '2026-10-01');
        $this->assertSame(['incomplete', 'Marcación incompleta'], [$day['day_status'], $day['day_status_label']]);
        $cell = collect(app(AttendanceCalendarService::class)->paginate(new CalendarFilters($this->tunesa->id, '2026-10', viewer: $this->hr))->items())
            ->firstWhere('user_id', $yohana->id)['cells']['2026-10-01'];
        $this->assertSame(['incomplete', 'Marcación incompleta'], [$cell['state'], $cell['label']]);

        $this->actingAs($this->hr)->put(route('attendance.manual.update', ['id' => $yohana->id, 'date' => '2026-10-01']), [
            'clock_in' => '07:58', 'clock_out' => '15:00', 'reason' => 'Olvidó marcar la salida; confirmado por su supervisor.',
        ])->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'attendance.manual_edit')->sole();
        $this->assertSame([$this->hr->id, 'Olvidó marcar la salida; confirmado por su supervisor.'], [$log->user_id, $log->meta['context']['reason']]);
        $this->assertSame('2026-10-01 15:00', collect($log->meta['changes'])->firstWhere('field', 'clock_out_at')['new']);
        $after = $this->day($this->report('2026-10-01', '2026-10-01'), $yohana, '2026-10-01');
        $this->assertSame(['normal', '6:02'], [$after['day_status'], AttendanceReportService::hm($after['worked_minutes'])]);
    }

    public function test_cp08_october_close_final_totals_equal_the_sum_of_daily_rows(): void
    {
        $this->punch($this->p['Juan'], '2026-10-01 08:06', 'clock-in');
        $this->punch($this->p['Juan'], '2026-10-01 15:03', 'clock-out');
        $this->punch($this->p['Juan'], '2026-10-02 08:25', 'clock-in');
        $this->punch($this->p['Juan'], '2026-10-02 16:30', 'clock-out');
        LeaveRequest::create(['tenant_id' => $this->tunesa->id, 'user_id' => $this->p['Juana']->id, 'type' => 'vacation',
            'start_date' => '2026-10-05', 'end_date' => '2026-10-09', 'days' => 5, 'status' => 'approved']);
        LeaveRequest::create(['tenant_id' => $this->tunesa->id, 'user_id' => $this->p['Fidel']->id, 'type' => 'medical_leave',
            'start_date' => '2026-10-12', 'end_date' => '2026-10-13', 'days' => 2, 'status' => 'approved']);
        $this->travelTo(Carbon::parse('2026-11-02 09:00', 'America/Lima'));

        $report = $this->report('2026-10-01', '2026-10-31');
        foreach ($report as $r) {
            $days = collect($r['days']);
            $s = $r['summary'];
            $this->assertSame($s['worked_minutes'], $days->sum('worked_minutes'), "Horas de {$r['name']}");
            $this->assertSame($s['late_minutes'], $days->sum('late_minutes'), "Tardanza de {$r['name']}");
            $this->assertSame($s['overtime_day_minutes'] + $s['overtime_night_minutes'], $days->sum('overtime_day_minutes') + $days->sum('overtime_night_minutes'));
            $this->assertSame($s['absences_unjustified'], $days->where('day_status', 'absent')->count(), "Faltas de {$r['name']}");
            $this->assertSame($s['rest_days'], $days->where('day_status', 'rest')->count());
            $this->assertSame($s['vacation_days'], $days->where('day_status', 'vacation')->count());
            $this->assertSame($s['medical_leave_days'], $days->where('day_status', 'medical_leave')->count());
            $this->assertSame($s['days_worked'], $days->where('status', 'worked')->count());
        }
        $juan = $report[$this->p['Juan']->id]['summary'];
        $this->assertSame([22, 2, 20, 9, 25], [$juan['scheduled_days'], $juan['days_worked'], $juan['absences_unjustified'], $juan['rest_days'], $juan['late_minutes']]);
        $this->assertSame([5, 2], [$report[$this->p['Juana']->id]['summary']['vacation_days'], $report[$this->p['Fidel']->id]['summary']['medical_leave_days']]);
    }

    public function test_cp09_calendar_branch_filter_shows_only_its_collaborators(): void
    {
        $lima = $this->makeBranch($this->tunesa, ['name' => 'Ag. Lima']);
        $otro = $this->makeUser($this->tunesa, 'employee', ['first_name' => 'Otro', 'last_name' => 'Agencia']);
        $otro->branches()->attach($lima->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);

        $ids = fn (?int $branch) => collect(app(AttendanceCalendarService::class)->paginate(new CalendarFilters($this->tunesa->id, '2026-10', branchId: $branch, viewer: $this->hr))->items())->pluck('user_id')->sort()->values()->all();

        $this->assertSame(collect($this->p)->pluck('id')->sort()->values()->all(), $ids($this->trujillo->id));
        $this->assertSame([$otro->id], $ids($lima->id));
    }

    public function test_hq04_area_and_collaborator_filters_already_work(): void
    {
        $operaciones = \App\Modules\Core\Models\Department::create(['tenant_id' => $this->tunesa->id, 'name' => 'Operaciones']);
        $this->p['Fidel']->update(['department_id' => $operaciones->id]);
        $ids = fn (array $f) => collect(app(AttendanceCalendarService::class)->paginate(new CalendarFilters($this->tunesa->id, '2026-10', ...$f, viewer: $this->hr))->items())->pluck('user_id')->all();

        $this->assertSame([$this->p['Fidel']->id], $ids(['departmentId' => $operaciones->id]));
        $this->assertSame([$this->p['Juana']->id], $ids(['search' => 'Pereda']));
    }

    public function test_hq04_calendar_remembers_the_last_branch_and_paints_each_schedule_color(): void
    {
        $this->a->update(['color' => '#3C729E']);
        $lima = $this->makeBranch($this->tunesa, ['name' => 'Ag. Lima']);
        $otro = $this->makeUser($this->tunesa, 'employee', ['first_name' => 'Otro', 'last_name' => 'Agencia']);
        $otro->branches()->attach($lima->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $this->travelTo(Carbon::parse('2026-10-20 10:00', 'America/Lima'));
        $cookie = 'calendar_branch_' . $this->hr->id;

        // Elige Ag. Trujillo: queda recordada
        $first = $this->actingAs($this->hr)->get(route('attendance.calendar', ['month' => '2026-10', 'branch_id' => $this->trujillo->id]))->assertOk()
            ->assertSee('Pereda')->assertDontSee('Agencia</', false)->assertCookie($cookie, (string) $this->trujillo->id);

        // Vuelve sin indicar sede: usa la última elegida, con la franja de color del horario y su leyenda
        $this->withCookie($cookie, (string) $this->trujillo->id)->actingAs($this->hr)->get(route('attendance.calendar', ['month' => '2026-10']))->assertOk()
            ->assertSee('Pereda')->assertDontSee('Otro Agencia')
            ->assertSee('box-shadow: inset 0 -3px 0 #3C729E', false)->assertSee('Horario A');

        // "Todas las sucursales" también se recuerda; una sede fuera de alcance se ignora
        $this->actingAs($this->hr)->get(route('attendance.calendar', ['month' => '2026-10', 'branch_id' => '']))->assertCookie($cookie, '');
        $this->withCookie($cookie, '999999')->actingAs($this->hr)->get(route('attendance.calendar', ['month' => '2026-10']))->assertOk()->assertSee('Otro');
    }
}
