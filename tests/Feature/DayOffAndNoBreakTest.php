<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TenantContext;
use App\Modules\HR\Models\EmployeeScheduleDay;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Shift;
use App\Modules\HR\Services\ScheduleProgramming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Casos reales de Tunesa (21/11):
 *  - Elder: el 16 y 17/11 están programados como libres, pero el calendario mostraba «Faltó»: el cierre nocturno
 *    registró la falta antes de que se guardara la programación, y la falta quedaba.
 *  - Giancarlo: su turno no tiene break (interruptor apagado) y la app le seguía ofreciendo «Inicio break».
 */
class DayOffAndNoBreakTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;

    private Branch $branch;

    private User $hr;

    private User $emp;

    private Shift $manana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->withoutPunchTimeLimits();
        $this->travelTo(Carbon::parse('2026-11-20 14:00:00', 'America/Lima'));
        $this->acme = $this->makeTenant('acme');
        app(TenantContext::class)->set($this->acme);
        $this->branch = $this->makeBranch($this->acme);
        $this->hr = $this->makeUser($this->acme, 'hr_manager');
        $this->emp = $this->makeUser($this->acme, 'employee', ['first_name' => 'Elder', 'last_name' => 'Ruiz']);
        $this->emp->branches()->attach($this->branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $this->manana = Shift::create(['tenant_id' => $this->acme->id, 'branch_id' => $this->branch->id, 'name' => 'MAÑANA',
            'start_time' => '07:00', 'end_time' => '13:30', 'daily_hours' => 6.5, 'grace_period_minutes' => 10,
            'break_allowed' => false, 'working_days' => [0, 1, 2, 3, 4, 5, 6]]);
        EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'shift_id' => $this->manana->id,
            'branch_id' => $this->branch->id, 'effective_from' => '2020-01-01', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function absence(string $date, array $extra = []): Attendance
    {
        return Attendance::create($extra + ['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'branch_id' => $this->branch->id,
            'shift_id' => $this->manana->id, 'work_date' => $date, 'status' => 'absent', 'notes' => 'Falta registrada automáticamente: no marcó entrada.']);
    }

    private function program(array $days): void
    {
        app(ScheduleProgramming::class)->saveRange($this->hr, $this->branch, [$this->emp->id], '2026-11-16', '2026-11-22',
            [$this->emp->id => $days], 'Programación de horarios');
    }

    public function test_saving_a_day_as_free_voids_the_automatic_absence_already_recorded(): void
    {
        $free = $this->absence('2026-11-16');
        $worked = $this->absence('2026-11-17');      // sigue siendo día de trabajo: la falta queda
        $manual = $this->absence('2026-11-18', ['manual_edited_at' => now(), 'manual_edited_by' => $this->hr->id]);
        $justified = $this->absence('2026-11-19', ['justification_status' => 'pending']);

        $this->program(['2026-11-16' => null, '2026-11-17' => $this->manana->id, '2026-11-18' => null, '2026-11-19' => null]);

        $this->assertNull(Attendance::withTrashed()->find($free->id));
        $this->assertTrue(AuditLog::where('action', 'attendance.absence_voided')->where('subject_id', $free->id)->exists());
        // Lo que puso o revisó una persona no se toca solo
        $this->assertNotNull(Attendance::find($worked->id));
        $this->assertNotNull(Attendance::find($manual->id));
        $this->assertNotNull(Attendance::find($justified->id));

        // El calendario muestra el día libre, ya no «Faltó»
        $cells = app(\App\Modules\Attendance\Services\AttendanceCalendarService::class)
            ->rows(collect([$this->emp->fresh()]), new \App\Modules\Attendance\Services\CalendarFilters($this->acme->id, '2026-11', userId: $this->emp->id))[0]['cells'];
        $this->assertSame('rest', $cells['2026-11-16']['state']);
        $this->assertSame('absent', $cells['2026-11-17']['state']);
    }

    public function test_repair_command_only_touches_days_saved_as_free(): void
    {
        $free = $this->absence('2026-11-16');
        $unprogrammed = $this->absence('2026-11-17');    // sin fila en la programación: no se deduce nada
        $today = $this->absence('2026-11-20');           // hoy aún no se juzga
        foreach (['2026-11-16', '2026-11-20'] as $date) {
            EmployeeScheduleDay::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'work_date' => $date, 'shift_id' => null, 'source' => 'calendar']);
        }
        app(TenantContext::class)->clear();

        $this->artisan('attendance:void-absences-on-days-off')->expectsOutputToContain('Faltas por anular (usa --apply): 1')->assertSuccessful();
        $this->assertNotNull(Attendance::withoutGlobalScopes()->find($free->id));

        $this->artisan('attendance:void-absences-on-days-off', ['--apply' => true])->expectsOutputToContain('Faltas anuladas: 1')->assertSuccessful();
        $this->assertNull(Attendance::withoutGlobalScopes()->withTrashed()->find($free->id));
        $this->assertNotNull(Attendance::withoutGlobalScopes()->find($unprogrammed->id));
        $this->assertNotNull(Attendance::withoutGlobalScopes()->find($today->id));
    }

    public function test_a_shift_without_break_offers_only_clock_out_after_clock_in(): void
    {
        Attendance::create(['tenant_id' => $this->acme->id, 'user_id' => $this->emp->id, 'branch_id' => $this->branch->id,
            'shift_id' => $this->manana->id, 'work_date' => '2026-11-20', 'clock_in_at' => '2026-11-20 07:02:00', 'status' => 'present']);

        $this->actingIn($this->emp, $this->acme)->getJson('/api/attendance/today')->assertOk()
            ->assertJsonPath('next_action.action', 'clock_out')
            ->assertJsonMissingPath('next_action.actions');
        $this->actingIn($this->emp, $this->acme)->getJson('/api/schedule?from=2026-11-20&to=2026-11-20')
            ->assertOk()->assertJsonPath('data.0.shift.break_allowed', false);

        // Con break, como siempre: inicio de break o salida
        $this->manana->update(['break_allowed' => true, 'break_duration_minutes' => 60]);
        $this->actingIn($this->emp, $this->acme)->getJson('/api/attendance/today')->assertOk()
            ->assertJsonPath('next_action.action', 'break_or_clock_out')
            ->assertJsonPath('next_action.actions.0.action', 'break_start');
    }
}
