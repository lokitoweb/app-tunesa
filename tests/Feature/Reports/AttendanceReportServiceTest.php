<?php

namespace Tests\Feature\Reports;

use App\Models\Attendance;
use App\Models\EmployeeShift;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Reports\AttendanceReportService;
use App\Services\Reports\ReportFilters;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Cálculo del "Reporte de horas y faltas": horas efectivas, refrigerio pagado,
 * horas extra diurnas/nocturnas (22:00–06:00), turnos que cruzan medianoche,
 * feriados, solicitudes aprobadas, rotativos y alcance del supervisor.
 */
class AttendanceReportServiceTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private AttendanceReportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->travelTo(Carbon::parse('2026-10-09 12:00:00', 'America/Lima'));
        $this->service = new AttendanceReportService();
    }

    private function shift(array $attrs = []): Shift
    {
        return new Shift(array_merge([
            'name' => 'Diurno', 'start_time' => '08:00:00', 'end_time' => '17:00:00',
            'daily_hours' => 8, 'grace_period_minutes' => 10, 'crosses_midnight' => false,
            'working_days' => [1, 2, 3, 4, 5], 'type' => 'fixed',
        ], $attrs));
    }

    private function attendance(string $date, string $in, ?string $out, array $attrs = []): Attendance
    {
        return new Attendance(array_merge([
            'work_date' => $date, 'clock_in_at' => $in, 'clock_out_at' => $out, 'status' => 'present',
        ], $attrs));
    }

    // =========================================================================
    // CÁLCULO POR MARCACIÓN
    // =========================================================================

    public function test_day_shift_with_unpaid_lunch_and_daytime_overtime(): void
    {
        $att = $this->attendance('2026-10-05', '2026-10-05 08:00:00', '2026-10-05 19:30:00', [
            'break_start_at' => '2026-10-05 13:00:00', 'break_end_at' => '2026-10-05 14:00:00', 'break_minutes' => 60, 'late_minutes' => 0,
        ]);

        $r = $this->service->calculate($att, $this->shift(), lunchPaid: false);

        $this->assertSame(630, $r['worked_minutes']);   // 11.5 h − 1 h refrigerio
        $this->assertSame(60, $r['break_minutes']);
        $this->assertSame(150, $r['overtime_day_minutes']);
        $this->assertSame(0, $r['overtime_night_minutes']);
        $this->assertSame(0, $r['night_minutes']);
        $this->assertSame(0, $r['early_minutes']);
    }

    public function test_paid_lunch_is_not_discounted(): void
    {
        $att = $this->attendance('2026-10-05', '2026-10-05 08:00:00', '2026-10-05 19:30:00', [
            'break_start_at' => '2026-10-05 13:00:00', 'break_end_at' => '2026-10-05 14:00:00', 'break_minutes' => 60,
        ]);

        $r = $this->service->calculate($att, $this->shift(), lunchPaid: true);

        $this->assertSame(690, $r['worked_minutes']);
        $this->assertSame(0, $r['break_minutes']);
        $this->assertSame(210, $r['overtime_day_minutes']);
    }

    public function test_night_shift_crossing_midnight_overtime_falls_in_the_morning(): void
    {
        // 22:00 → 08:00: 10 h; nocturnas 22–06 = 8 h; extra = 2 h de cola (06–08, diurnas)
        $shift = $this->shift(['start_time' => '22:00:00', 'end_time' => '06:00:00', 'crosses_midnight' => true]);
        $att   = $this->attendance('2026-10-05', '2026-10-05 22:00:00', '2026-10-06 08:00:00');

        $r = $this->service->calculate($att, $shift, lunchPaid: true);

        $this->assertSame(600, $r['worked_minutes']);
        $this->assertSame(480, $r['night_minutes']);
        $this->assertSame(120, $r['overtime_day_minutes']);
        $this->assertSame(0, $r['overtime_night_minutes']);
        $this->assertSame(0, $r['early_minutes'], 'Salió después de las 06:00 del día siguiente');
    }

    public function test_evening_shift_overtime_after_22_is_nocturnal(): void
    {
        // 14:00 → 01:00 con 1 h de refrigerio no pagado: 10 h netas; extra = 23:00–01:00 (nocturnas)
        $shift = $this->shift(['start_time' => '14:00:00', 'end_time' => '22:00:00']);
        $att   = $this->attendance('2026-10-05', '2026-10-05 14:00:00', '2026-10-06 01:00:00', [
            'break_start_at' => '2026-10-05 18:00:00', 'break_end_at' => '2026-10-05 19:00:00', 'break_minutes' => 60,
        ]);

        $r = $this->service->calculate($att, $shift, lunchPaid: false);

        $this->assertSame(600, $r['worked_minutes']);
        $this->assertSame(180, $r['night_minutes']);
        $this->assertSame(120, $r['overtime_night_minutes']);
        $this->assertSame(0, $r['overtime_day_minutes']);
    }

    public function test_overtime_straddling_22_is_split(): void
    {
        // 13:00 → 23:30 (refrigerio pagado): 10.5 h; extra 21:00–23:30 = 60 diurnas + 90 nocturnas
        $shift = $this->shift(['start_time' => '13:00:00', 'end_time' => '21:00:00']);
        $att   = $this->attendance('2026-10-05', '2026-10-05 13:00:00', '2026-10-05 23:30:00');

        $r = $this->service->calculate($att, $shift, lunchPaid: true);

        $this->assertSame(630, $r['worked_minutes']);
        $this->assertSame(90, $r['night_minutes']);
        $this->assertSame(60, $r['overtime_day_minutes']);
        $this->assertSame(90, $r['overtime_night_minutes']);
    }

    public function test_night_break_is_removed_from_night_hours(): void
    {
        // 22:00 → 07:00 con descanso 02:00–03:00 no pagado: 8 h netas, nocturnas 7 h, extra 0
        $shift = $this->shift(['start_time' => '22:00:00', 'end_time' => '07:00:00', 'crosses_midnight' => true]);
        $att   = $this->attendance('2026-10-05', '2026-10-05 22:00:00', '2026-10-06 07:00:00', [
            'break_start_at' => '2026-10-06 02:00:00', 'break_end_at' => '2026-10-06 03:00:00', 'break_minutes' => 60,
        ]);

        $r = $this->service->calculate($att, $shift, lunchPaid: false);

        $this->assertSame(480, $r['worked_minutes']);
        $this->assertSame(420, $r['night_minutes']);
        $this->assertSame(0, $r['overtime_day_minutes'] + $r['overtime_night_minutes']);
    }

    public function test_early_departure_and_lateness(): void
    {
        $att = $this->attendance('2026-10-05', '2026-10-05 08:20:00', '2026-10-05 16:30:00', ['late_minutes' => 20, 'break_minutes' => 60]);

        $r = $this->service->calculate($att, $this->shift(), lunchPaid: false);

        $this->assertSame(20, $r['late_minutes']);
        $this->assertSame(30, $r['early_minutes']);
        $this->assertSame(430, $r['worked_minutes'], 'Sin horas de descanso conocidas se descuenta break_minutes');
        $this->assertSame(0, $r['overtime_day_minutes']);
    }

    public function test_open_attendance_counts_only_lateness(): void
    {
        $att = $this->attendance('2026-10-05', '2026-10-05 08:15:00', null, ['late_minutes' => 5]);

        $r = $this->service->calculate($att, $this->shift(), lunchPaid: false);

        $this->assertSame(0, $r['worked_minutes']);
        $this->assertSame(5, $r['late_minutes']);
    }

    public function test_night_overlap_helper(): void
    {
        $t = fn (string $s) => Carbon::parse($s, 'UTC')->getTimestamp();

        $this->assertSame(0, AttendanceReportService::nightOverlap($t('2026-10-05 06:00'), $t('2026-10-05 22:00')));
        $this->assertSame(8 * 3600, AttendanceReportService::nightOverlap($t('2026-10-05 20:00'), $t('2026-10-06 08:00')));
        $this->assertSame(3600, AttendanceReportService::nightOverlap($t('2026-10-05 05:00'), $t('2026-10-05 07:00')));
        // Dos noches seguidas
        $this->assertSame(16 * 3600, AttendanceReportService::nightOverlap($t('2026-10-05 21:00'), $t('2026-10-07 07:00')));
    }

    // =========================================================================
    // REPORTE COMPLETO (base de datos)
    // =========================================================================

    /** @return array{0: Tenant, 1: User, 2: Shift} */
    private function companyWithEmployee(array $shiftAttrs = [], array $assignment = []): array
    {
        $tenant = $this->makeTenant('acme');
        $branch = $this->makeBranch($tenant);
        $emp    = $this->makeUser($tenant, 'employee', ['first_name' => 'Ana', 'last_name' => 'Rojas', 'employee_code' => 'E001']);
        $emp->branches()->attach($branch->id, ['is_primary' => true]);

        $shift = Shift::create(array_merge([
            'tenant_id' => $tenant->id, 'name' => 'L-V', 'start_time' => '08:00', 'end_time' => '17:00',
            'daily_hours' => 8, 'working_days' => [1, 2, 3, 4, 5],
        ], $shiftAttrs));

        EmployeeShift::create(array_merge([
            'tenant_id' => $tenant->id, 'user_id' => $emp->id, 'shift_id' => $shift->id,
            'branch_id' => $branch->id, 'effective_from' => '2026-01-01', 'is_active' => true,
        ], $assignment));

        return [$tenant, $emp, $shift];
    }

    private function filters(Tenant $tenant, string $from, string $to, ?User $viewer = null): ReportFilters
    {
        return new ReportFilters($tenant->id, $from, $to, viewer: $viewer);
    }

    private function only(array $reports, User $user): array
    {
        return collect($reports)->firstWhere('user_id', $user->id);
    }

    public function test_holiday_leave_justification_and_unjustified_absence(): void
    {
        [$tenant, $emp, $shift] = $this->companyWithEmployee();
        $branchId = $emp->branches()->first()->id;

        // Semana lun 28/09 – dom 04/10/2026
        Attendance::create(['tenant_id' => $tenant->id, 'user_id' => $emp->id, 'branch_id' => $branchId, 'shift_id' => $shift->id,
            'work_date' => '2026-09-28', 'clock_in_at' => '2026-09-28 08:05:00', 'clock_out_at' => '2026-09-28 18:00:00',
            'break_minutes' => 60, 'late_minutes' => 0, 'status' => 'present']);
        Holiday::create(['tenant_id' => $tenant->id, 'date' => '2026-09-29', 'name' => 'Feriado de prueba']);
        LeaveRequest::create(['tenant_id' => $tenant->id, 'user_id' => $emp->id, 'type' => 'vacation',
            'start_date' => '2026-09-30', 'end_date' => '2026-09-30', 'days' => 1, 'status' => 'approved']);
        // 01/10: no marcó → falta injustificada
        Attendance::create(['tenant_id' => $tenant->id, 'user_id' => $emp->id, 'branch_id' => $branchId,
            'work_date' => '2026-10-02', 'status' => 'absent', 'justification_status' => 'approved']);

        $report = $this->only($this->service->forUsers(collect([$emp]), $this->filters($tenant, '2026-09-28', '2026-10-04')), $emp);
        $s = $report['summary'];

        $this->assertSame(1, $s['days_worked']);
        $this->assertSame(1, $s['absences_unjustified']);
        $this->assertSame(2, $s['absences_justified']);
        $this->assertSame(4, $s['scheduled_days'], 'Lun, mié, jue y vie (el martes es feriado)');
        $this->assertSame(535, $s['worked_minutes']); // 9 h 55 − 60
        $this->assertSame(55, $s['overtime_day_minutes']);
        $this->assertSame(8.92, $s['worked_hours']);

        $byDate = collect($report['days'])->keyBy('date');
        $this->assertSame('absent', $byDate['2026-10-01']['status']);
        $this->assertSame('justified', $byDate['2026-09-30']['status']);
        $this->assertStringContainsString('Vacaciones', $byDate['2026-09-30']['note']);
        $this->assertSame('justified', $byDate['2026-10-02']['status']);
        $this->assertFalse($byDate->has('2026-09-29'), 'El feriado no es falta');
        $this->assertFalse($byDate->has('2026-10-03'), 'El sábado es descanso');
    }

    public function test_lunch_paid_tenant_setting_changes_worked_hours(): void
    {
        [$tenant, $emp, $shift] = $this->companyWithEmployee();
        Attendance::create(['tenant_id' => $tenant->id, 'user_id' => $emp->id, 'branch_id' => $emp->branches()->first()->id,
            'shift_id' => $shift->id, 'work_date' => '2026-10-05',
            'clock_in_at' => '2026-10-05 08:00:00', 'clock_out_at' => '2026-10-05 17:00:00',
            'break_start_at' => '2026-10-05 13:00:00', 'break_end_at' => '2026-10-05 14:00:00', 'break_minutes' => 60, 'status' => 'present']);

        $f = $this->filters($tenant, '2026-10-05', '2026-10-05');
        $this->assertSame(480, $this->only((new AttendanceReportService())->forUsers(collect([$emp]), $f), $emp)['summary']['worked_minutes']);

        $row = TenantSetting::editableRowFor($tenant);
        $row->lunch_paid = true;
        $row->save();

        $s = $this->only((new AttendanceReportService())->forUsers(collect([$emp]), $f), $emp)['summary'];
        $this->assertSame(540, $s['worked_minutes']);
        $this->assertSame(60, $s['overtime_day_minutes']);
    }

    public function test_rotating_shift_rest_days_are_not_absences(): void
    {
        // 2x2 desde el lunes 28/09: trabaja 28 y 29, descansa 30 y 01, trabaja 02 y 03
        [$tenant, $emp] = $this->companyWithEmployee(
            ['type' => 'rotating', 'rotation_work_days' => 2, 'rotation_rest_days' => 2, 'working_days' => []],
            ['rotation_anchor_date' => '2026-09-28'],
        );

        $s = $this->only($this->service->forUsers(collect([$emp]), $this->filters($tenant, '2026-09-28', '2026-10-04')), $emp)['summary'];

        $this->assertSame(4, $s['absences_unjustified']);
        $this->assertSame(4, $s['scheduled_days']);
        $this->assertSame(0, $s['days_worked']);
    }

    public function test_night_shift_report_and_future_days_are_not_absences(): void
    {
        [$tenant, $emp, $shift] = $this->companyWithEmployee(
            ['start_time' => '22:00', 'end_time' => '06:00', 'crosses_midnight' => true, 'working_days' => [0, 1, 2, 3, 4, 5, 6]],
        );
        Attendance::create(['tenant_id' => $tenant->id, 'user_id' => $emp->id, 'branch_id' => $emp->branches()->first()->id,
            'shift_id' => $shift->id, 'work_date' => '2026-10-08',
            'clock_in_at' => '2026-10-08 22:00:00', 'clock_out_at' => '2026-10-09 07:00:00', 'status' => 'present']);

        // Hoy es 09/10: 09 y 10 aún no cierran → no son faltas
        $s = $this->only($this->service->forUsers(collect([$emp]), $this->filters($tenant, '2026-10-08', '2026-10-10')), $emp)['summary'];

        $this->assertSame(1, $s['days_worked']);
        $this->assertSame(0, $s['absences_unjustified']);
        $this->assertSame(480, $s['night_minutes']);
        $this->assertSame(60, $s['overtime_day_minutes']);
        $this->assertSame(1.0, (float) $s['overtime_day_hours']);
    }

    public function test_supervisor_sees_only_team_and_global_view_spans_companies(): void
    {
        [$tenant, $emp] = $this->companyWithEmployee();
        $supervisor = $this->makeUser($tenant, 'supervisor');
        $emp->branches()->first()->update(['supervisor_id' => $supervisor->id]);
        $outsider = $this->makeUser($tenant, 'employee');
        $outsider->branches()->attach($this->makeBranch($tenant)->id);

        $other = $this->makeTenant('globex');
        $foreign = $this->makeUser($other, 'employee');

        $ids = $this->service->employeesQuery($this->filters($tenant, '2026-10-01', '2026-10-05', $supervisor))->pluck('id')->all();
        $this->assertSame([$emp->id], $ids);

        $admin = $this->makeUser($tenant, 'company_admin');
        $ids = $this->service->employeesQuery($this->filters($tenant, '2026-10-01', '2026-10-05', $admin))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$emp->id, $supervisor->id, $outsider->id], $ids);

        $super = $this->makeUser(null, 'super_admin');
        $ids = $this->service->employeesQuery(new ReportFilters(null, '2026-10-01', '2026-10-05', viewer: $super))->pluck('id')->all();
        $this->assertContains($foreign->id, $ids);
        $this->assertContains($emp->id, $ids);
    }

    public function test_totals_and_pagination(): void
    {
        [$tenant, $emp] = $this->companyWithEmployee();
        $f = $this->filters($tenant, '2026-10-05', '2026-10-06');

        $page = $this->service->paginate($f, 10);
        $this->assertSame(1, $page->total());
        $this->assertSame('Ana Rojas', $page->first()['name']);
        $this->assertArrayNotHasKey('days', $page->first());

        $totals = $this->service->totals($f);
        $this->assertSame(1, $totals['employees']);
        $this->assertSame(2, $totals['absences_unjustified']);
    }
}
