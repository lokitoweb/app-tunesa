<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Services\ManualAttendanceEdit;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TenantContext;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Shift;
use App\Modules\HR\Services\ScheduleProgramming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Caso real (Elder, 18/11): la falta automática quedó con el turno MAÑANA (07:00–13:30); RR. HH. reprogramó el
 * día a TARDE (13:30–20:00) y cargó a mano la entrada 13:35 y la salida 20:00. Se recalculaba con MAÑANA:
 * 6 h 35 de tardanza, 0 h de jornada y 6 h 25 de horas extra. La jornada se juzga con el turno programado del día.
 */
class ProgrammedShiftRecalcTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;

    private Branch $branch;

    private User $hr;

    private User $emp;

    private Shift $manana;

    private Shift $tarde;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00', 'America/Lima'));
        $this->acme = $this->makeTenant('acme');
        app(TenantContext::class)->set($this->acme);
        $this->branch = $this->makeBranch($this->acme);
        $this->hr = $this->makeUser($this->acme, 'hr_manager');
        $this->emp = $this->makeUser($this->acme, 'employee', ['first_name' => 'Elder', 'last_name' => 'Ruiz']);
        $this->emp->branches()->attach($this->branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $base = ['tenant_id' => $this->acme->id, 'branch_id' => $this->branch->id, 'grace_period_minutes' => 10, 'break_allowed' => false, 'working_days' => [0, 1, 2, 3, 4, 5, 6]];
        $this->manana = Shift::create($base + ['name' => 'MAÑANA', 'start_time' => '07:00', 'end_time' => '13:30', 'daily_hours' => 6.5]);
        $this->tarde = Shift::create($base + ['name' => 'TARDE', 'start_time' => '13:30', 'end_time' => '20:00', 'daily_hours' => 6.5]);
        EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'shift_id' => $this->manana->id,
            'branch_id' => $this->branch->id, 'effective_from' => '2020-01-01', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function programTarde(): void
    {
        app(ScheduleProgramming::class)->saveRange($this->hr, $this->branch, [$this->emp->id], '2026-11-16', '2026-11-22',
            [$this->emp->id => ['2026-11-18' => $this->tarde->id]], 'Cambio a la tarde');
    }

    private function correct(): Attendance
    {
        return app(ManualAttendanceEdit::class)->save($this->hr, $this->emp, '2026-11-18', '13:35', '20:00', null, 'Marcó en papel');
    }

    public function test_a_manual_correction_uses_the_programmed_shift_not_the_one_saved_with_the_absence(): void
    {
        // La falta automática quedó con el turno de entonces
        Attendance::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'branch_id' => $this->branch->id,
            'shift_id' => $this->manana->id, 'work_date' => '2026-11-18', 'status' => 'absent']);
        $this->programTarde();

        $day = $this->correct();
        $this->assertSame($this->tarde->id, $day->shift_id);
        $this->assertSame(0, (int) $day->late_minutes);               // 13:35 dentro de la tolerancia de las 13:30
        $this->assertSame(385, $day->regularMinutes());               // 13:35 → 20:00 = 06:25:00 de jornada
        $this->assertEquals(0.0, (float) $day->overtime_hours);       // antes: 6.42 h extra
        $this->assertNull($day->overtime_status);

        // La exportación lee lo mismo: tardanza 00:00:00, jornada 06:25:00, extra 00:00:00
        $csv = $this->actingAs($this->hr)->get(route('reports.export.excel', ['start_date' => '2026-11-18', 'end_date' => '2026-11-18']))->streamedContent();
        $this->assertStringContainsString('06:25:00', $csv);
        $this->assertStringNotContainsString('06:35:00', $csv);
    }

    public function test_reprogramming_a_day_that_was_already_marked_recalculates_it(): void
    {
        // Corregida a mano con MAÑANA (sin programación): todo cae después de la salida oficial
        $day = $this->correct();
        $this->assertSame(395, (int) $day->late_minutes);
        $this->assertEquals(6.42, (float) $day->overtime_hours);
        $this->assertSame(Attendance::OVERTIME_PENDING, $day->overtime_status);

        // RR. HH. corrige la programación del día: la jornada se recalcula sola y queda en la bitácora
        $this->programTarde();
        $day->refresh();
        $this->assertSame($this->tarde->id, $day->shift_id);
        $this->assertSame(0, (int) $day->late_minutes);
        $this->assertSame(385, $day->regularMinutes());
        $this->assertEquals(0.0, (float) $day->overtime_hours);
        $this->assertNull($day->overtime_status);
        $this->assertSame('present', $day->status);
        $this->assertTrue(AuditLog::where('action', 'attendance.recalculated')->where('subject_id', $day->id)->exists());
    }

    public function test_batch_repairs_keep_overtime_that_hr_already_reviewed(): void
    {
        $day = $this->correct();
        $day->update(['overtime_status' => Attendance::OVERTIME_APPROVED, 'overtime_approved_hours' => 6.42]);
        \App\Modules\HR\Models\EmployeeScheduleDay::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id,
            'work_date' => '2026-11-18', 'shift_id' => $this->tarde->id, 'branch_id' => $this->branch->id, 'source' => 'calendar']);

        app(AttendanceService::class)->refreshDay($day->fresh(), keepReviewedOvertime: true);
        $day->refresh();
        $this->assertSame($this->tarde->id, $day->shift_id);           // turno y tardanza sí
        $this->assertSame(0, (int) $day->late_minutes);
        $this->assertEquals(6.42, (float) $day->overtime_hours);        // la extra aprobada no se toca sola
        $this->assertSame(Attendance::OVERTIME_APPROVED, $day->overtime_status);
    }
}
