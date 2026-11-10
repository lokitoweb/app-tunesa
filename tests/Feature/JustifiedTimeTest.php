<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\Reports\AttendanceReportService;
use App\Modules\Attendance\Services\Reports\ReportFilters;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TenantContext;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\Shift;
use App\Modules\Payroll\Services\PayrollExportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Faltas y tiempos justificados (decisión del propietario, 11/2026): las horas trabajadas quedan separadas de
 * las justificadas; RR. HH. elige con / sin goce al aprobar (sin valor por defecto); vacaciones y descanso médico
 * siempre con goce; tardanza o salida anticipada justificada no convierte el día en falta ni cuenta como tardanza.
 */
class JustifiedTimeTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;
    private User $hr;
    private User $emp;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->travelTo(Carbon::parse('2026-10-20 12:00', 'America/Lima'));
        $this->acme = $this->makeTenant('acme');
        app(TenantContext::class)->set($this->acme);
        $this->hr = $this->makeUser($this->acme, 'hr_manager');
        $this->emp = $this->makeUser($this->acme, 'employee', ['employee_code' => 'E1']);
        $this->branchId = $this->makeBranch($this->acme)->id;
        $this->emp->branches()->attach($this->branchId, ['is_primary' => true]);
        $shift = Shift::create(['tenant_id' => $this->acme->id, 'name' => 'L-V', 'start_time' => '08:00', 'end_time' => '17:00',
            'daily_hours' => 8, 'working_days' => [1, 2, 3, 4, 5]]);
        EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'shift_id' => $shift->id,
            'branch_id' => $this->branchId, 'effective_from' => '2026-01-01', 'is_active' => true]);
    }

    private function day(string $date, array $attrs): Attendance
    {
        return Attendance::create($attrs + ['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'branch_id' => $this->branchId,
            'work_date' => $date, 'justification_status' => 'pending', 'notes' => 'Motivo']);
    }

    public function test_every_kind_of_justified_time_is_counted_once_and_with_its_treatment(): void
    {
        $absentUnjustified = $this->day('2026-10-05', ['status' => 'absent', 'justification_status' => 'none']);
        $absentPaid = $this->day('2026-10-06', ['status' => 'absent']);
        $absentUnpaid = $this->day('2026-10-07', ['status' => 'absent']);
        $latePaid = $this->day('2026-10-08', ['status' => 'late', 'clock_in_at' => '2026-10-08 08:30:00', 'clock_out_at' => '2026-10-08 17:00:00',
            'late_minutes' => 30, 'worked_hours' => 7.5]);
        $forgot = $this->day('2026-10-09', ['status' => 'present', 'clock_in_at' => '2026-10-09 08:00:00', 'clock_out_at' => '2026-10-09 17:00:00',
            'worked_hours' => 8, 'punches_incomplete' => true]);

        // Sin elegir con / sin goce no se aprueba una falta ni una tardanza
        foreach ([$absentPaid, $latePaid] as $a) {
            try {
                $a->reviewJustification($this->hr, 'approved');
                $this->fail('Debía pedir con o sin goce');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('justification_paid', $e->errors());
            }
        }
        $absentPaid->reviewJustification($this->hr, 'approved', null, true);
        $absentUnpaid->reviewJustification($this->hr, 'approved', null, false);
        $latePaid->refresh()->reviewJustification($this->hr, 'approved', 'Tráfico', true);
        // Marca olvidada: no hay tiempo en juego, no se pide con / sin goce
        $forgot->reviewJustification($this->hr, 'approved');

        $this->assertSame(['excused', true], [$absentPaid->fresh()->status, $absentPaid->fresh()->justification_paid]);
        $this->assertSame(['late', true, 'Tardanza'], [$latePaid->fresh()->status, $latePaid->fresh()->justification_paid, $latePaid->fresh()->incidentLabel()]);
        $this->assertNull($forgot->fresh()->justification_paid);
        $this->assertSame('Marca olvidada', $forgot->fresh()->incidentLabel());
        $this->assertSame('Falta', $absentUnjustified->incidentLabel());

        // Permisos: con / sin goce los elige RR. HH.; vacaciones siempre con goce
        $perm = LeaveRequest::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'type' => 'permission',
            'start_date' => '2026-10-12', 'end_date' => '2026-10-13', 'days' => 2, 'reason' => 'Trámite', 'status' => 'pending']);
        $vac = LeaveRequest::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'type' => 'vacation',
            'start_date' => '2026-10-14', 'end_date' => '2026-10-14', 'days' => 1, 'reason' => 'Viaje', 'status' => 'pending']);
        try {
            $perm->review($this->hr, 'approved');
            $this->fail('Debía pedir con o sin goce');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('with_pay', $e->errors());
        }
        $perm->review($this->hr, 'approved', null, false);
        $vac->review($this->hr, 'approved', null, false); // se ignora: vacaciones siempre con goce
        $this->assertSame([false, true], [$perm->fresh()->with_pay, $vac->fresh()->with_pay]);

        // Planilla: cada cosa en su columna, una sola vez
        $row = app(PayrollExportService::class)->rows(2026, 10)->firstWhere('employee_code', 'E1');
        $this->assertSame(2, $row['days_worked'], 'La tardanza justificada sigue siendo día trabajado');
        $this->assertEqualsWithDelta(15.5, $row['worked_hours'], 0.001, 'Las horas justificadas no se suman como trabajadas');
        $this->assertSame([0, 0], [$row['late_count'], $row['late_minutes']], 'La tardanza justificada no cuenta como tardanza');
        $this->assertSame([30, 0], [$row['justified_minutes_paid'], $row['justified_minutes_unpaid']]);
        $this->assertSame([1, 1, 1], [$row['absences'], $row['excused_paid'], $row['excused_unpaid']]);
        $this->assertEquals([0, 2, 1], [$row['permission_paid_days'], $row['permission_unpaid_days'], $row['vacation_days']]);
        $this->assertEquals(0, $row['unclassified']);

        // Reporte de horas: mismos criterios
        $report = collect((new AttendanceReportService())->forUsers(collect([$this->emp]), new ReportFilters($this->acme->id, '2026-10-05', '2026-10-09')))
            ->firstWhere('user_id', $this->emp->id);
        $s = $report['summary'];
        $this->assertSame([2, 0, 30], [$s['days_worked'], $s['late_minutes'], $s['justified_minutes']]);
        $this->assertSame([1, 2], [$s['absences_unjustified'], $s['absences_justified']]);
        $notes = collect($report['days'])->keyBy('date');
        $this->assertSame('Falta justificada (con goce)', $notes['2026-10-06']['note']);
        $this->assertSame('Falta justificada (sin goce)', $notes['2026-10-07']['note']);
        $this->assertSame('Tardanza justificada (con goce)', $notes['2026-10-08']['note']);
    }

    public function test_old_approvals_without_treatment_are_flagged_not_guessed(): void
    {
        // Aprobada antes de este cambio: sin con / sin goce → "sin clasificar" en planilla, no se inventa
        $this->day('2026-10-06', ['status' => 'excused', 'justification_status' => 'approved']);
        LeaveRequest::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'type' => 'permission',
            'start_date' => '2026-10-12', 'end_date' => '2026-10-12', 'days' => 1, 'reason' => 'x', 'status' => 'approved']);

        $row = app(PayrollExportService::class)->rows(2026, 10)->firstWhere('employee_code', 'E1');
        $this->assertSame([0, 0, 0], [$row['absences'], $row['excused_paid'], $row['excused_unpaid']]);
        $this->assertEquals(2, $row['unclassified']);
    }
}
