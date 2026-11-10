<?php

namespace Tests\Feature\Reports;

use App\Modules\Attendance\Services\ManualAttendanceEdit;
use App\Modules\Attendance\Services\Reports\AttendanceReportService;
use App\Modules\Attendance\Services\Reports\ReportFilters;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TenantContext;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Holiday;
use App\Modules\HR\Models\Shift;
use App\Modules\Payroll\Services\PayrollExportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Bloque 2: una sola fuente de verdad. La jornada guardada (fórmula única AttendanceService::closedDayHours)
 * es lo que muestran el reporte de horas y la planilla, incluidos feriados con regla de extras (C1) y la base
 * de medio tiempo sin turno (C2), que antes el reporte recalculaba distinto.
 */
class SingleSourceOfTruthTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;
    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->travelTo(Carbon::parse('2026-10-20 12:00', 'America/Lima'));
        $this->acme = $this->makeTenant('acme');
        $this->hr = $this->makeUser($this->acme, 'hr_manager');
        app(TenantContext::class)->set($this->acme);
    }

    private function employee(string $code, array $attrs = [], bool $withShift = true): User
    {
        $branch = $this->makeBranch($this->acme, ['name' => "Sede {$code}"]);
        $emp = $this->makeUser($this->acme, 'employee', ['employee_code' => $code] + $attrs);
        $emp->branches()->attach($branch->id, ['is_primary' => true]);
        if ($withShift) {
            $shift = Shift::create(['tenant_id' => $this->acme->id, 'name' => "L-S {$code}", 'start_time' => '08:00', 'end_time' => '17:00',
                'daily_hours' => 8, 'working_days' => [1, 2, 3, 4, 5, 6], 'break_allowed' => true, 'break_duration_minutes' => 60]);
            EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $emp->id, 'shift_id' => $shift->id,
                'branch_id' => $branch->id, 'effective_from' => '2026-01-01', 'is_active' => true]);
        }

        return $emp;
    }

    /** @return array<string, array{worked:int, overtime:int}> minutos por día según el reporte */
    private function reportDays(User $emp): array
    {
        $report = collect((new AttendanceReportService())->forUsers(collect([$emp]), new ReportFilters($this->acme->id, '2026-10-01', '2026-10-19')))
            ->firstWhere('user_id', $emp->id);

        return collect($report['days'])->filter(fn ($d) => ($d['worked_minutes'] ?? 0) > 0)
            ->mapWithKeys(fn ($d) => [$d['date'] => ['worked' => $d['worked_minutes'], 'overtime' => $d['overtime_day_minutes'] + $d['overtime_night_minutes']]])
            ->all();
    }

    public function test_report_and_payroll_show_exactly_the_stored_day(): void
    {
        $emp = $this->employee('E1');
        $part = $this->employee('E2', ['contract_type' => 'part_time'], withShift: false);
        // Día especial: todo lo trabajado es extra (regla de la empresa)
        Holiday::create(['tenant_id' => $this->acme->id, 'date' => '2026-10-10', 'name' => 'Inventario', 'kind' => 'special', 'overtime_rule' => 'all']);

        $edit = app(ManualAttendanceEdit::class);
        $days = [
            [$emp, '2026-10-05', '08:00', '18:30'],  // 10 h 30 − 1 h de refrigerio = 9 h 30; extra después de las 17:00
            [$emp, '2026-10-06', '08:20', '17:00'],  // tardanza, sin extra
            [$emp, '2026-10-10', '08:00', '13:00'],  // día especial: todo es extra
            [$part, '2026-10-07', '09:00', '15:30'], // medio tiempo sin turno: base 4 h
        ];
        $stored = [];
        foreach ($days as [$who, $date, $in, $out]) {
            $a = $edit->save($this->hr, $who, $date, $in, $out, null, 'Carga de prueba');
            $stored[$who->id][$date] = ['worked' => (int) round($a->worked_hours * 60), 'overtime' => (int) round($a->overtime_hours * 60)];
        }

        $this->assertSame(['worked' => 570, 'overtime' => 90], $stored[$emp->id]['2026-10-05']);
        $this->assertSame(300, $stored[$emp->id]['2026-10-10']['overtime'], 'Día especial con regla "todo es extra"');
        $this->assertGreaterThan(0, $stored[$part->id]['2026-10-07']['overtime'], 'Medio tiempo: lo que pasa de 4 h es extra');

        // El reporte muestra lo guardado (antes: C1 sin regla del día especial y C2 con base de 8 h)
        $this->assertSame($stored[$emp->id], $this->reportDays($emp));
        $this->assertSame($stored[$part->id], $this->reportDays($part));

        // La planilla suma lo mismo
        $payroll = app(PayrollExportService::class)->rows(2026, 10)->keyBy('employee_code');
        $this->assertEqualsWithDelta(array_sum(array_column($stored[$emp->id], 'worked')) / 60, $payroll['E1']['worked_hours'], 0.01);
        $this->assertEqualsWithDelta(array_sum(array_column($stored[$part->id], 'worked')) / 60, $payroll['E2']['worked_hours'], 0.01);
    }

    public function test_recalculate_command_previews_and_applies_with_the_same_formula(): void
    {
        $emp = $this->employee('E1');
        $a = app(ManualAttendanceEdit::class)->save($this->hr, $emp, '2026-10-05', '08:00', '18:30', null, 'Carga de prueba');
        // Simula una jornada guardada con la regla anterior (trabajado − 8 h = 30 min de extra)
        $a->update(['overtime_hours' => 0.5]);

        $this->artisan('attendance:recalculate', ['tenant' => 'acme', '--from' => '2026-10-01', '--to' => '2026-10-19'])
            ->expectsOutputToContain('1 jornada(s) distintas')->assertSuccessful();
        $this->assertSame(0.5, (float) $a->fresh()->overtime_hours, 'Sin --apply no cambia nada');

        $this->artisan('attendance:recalculate', ['tenant' => 'acme', '--from' => '2026-10-01', '--apply' => true])->assertSuccessful();
        $this->assertSame(1.5, (float) $a->fresh()->overtime_hours);

        // Extras ya aprobadas por RR. HH.: no se cambian solas
        $a->refresh()->update(['overtime_hours' => 0.5, 'overtime_status' => 'approved', 'overtime_approved_hours' => 0.5]);
        $this->artisan('attendance:recalculate', ['tenant' => 'acme', '--from' => '2026-10-01', '--apply' => true])
            ->expectsOutputToContain('ya revisadas')->assertSuccessful();
        $this->assertSame(0.5, (float) $a->fresh()->overtime_hours);
    }
}
