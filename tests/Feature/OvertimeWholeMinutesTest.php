<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Las horas extra cuentan minutos completos, como las horas trabajadas. Antes, salir a las 15:00:40 daba
 * 0.01 h «por aprobar» (+00:01:00) y la jornada quedaba en 7:59 en vez de 8:00.
 */
class OvertimeWholeMinutesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_seconds_after_the_exit_are_not_overtime_and_the_stored_phantom_minute_is_cleaned(): void
    {
        $this->seedRoles();
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00', 'America/Lima'));
        $acme = $this->makeTenant('acme');
        $emp = $this->makeUser($acme);
        $branch = $this->makeBranch($acme);
        $emp->branches()->attach($branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $shift = Shift::create(['tenant_id' => $acme->id, 'name' => 'MAÑANA', 'start_time' => '07:00', 'end_time' => '15:00',
            'grace_period_minutes' => 10, 'break_allowed' => false, 'daily_hours' => 8, 'working_days' => [0, 1, 2, 3, 4, 5, 6]]);
        EmployeeShift::create(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'shift_id' => $shift->id, 'branch_id' => $branch->id,
            'effective_from' => '2020-01-01', 'is_active' => true]);

        $service = app(AttendanceService::class);
        $hours = fn (string $out) => $service->closedDayHours($emp, $acme, $shift, '2026-11-18', 'America/Lima',
            Carbon::parse('2026-11-18 06:56:00', 'America/Lima'), Carbon::parse("2026-11-18 {$out}", 'America/Lima'), 0, TenantSetting::forTenant($acme));

        // 40 segundos después de la salida: 8 h de jornada, 0 de extra
        [$worked, $overtime] = $hours('15:00:40');
        $this->assertEquals(8.0, $worked);
        $this->assertEquals(0.0, $overtime);

        // 31 minutos y 59 segundos después: 31 minutos de extra (0.52 h), nunca redondeado hacia arriba
        [, $overtime] = $hours('15:31:59');
        $this->assertSame(31, Attendance::minutes($overtime));

        // Jornadas ya guardadas con el minuto falso: el recálculo lo limpia; lo aprobado por RR. HH. no se toca
        $base = ['tenant_id' => $acme->id, 'user_id' => $emp->id, 'branch_id' => $branch->id, 'shift_id' => $shift->id, 'status' => 'present',
            'clock_in_at' => '2026-11-18 06:56:00', 'clock_out_at' => '2026-11-18 15:00:40', 'worked_hours' => 8.0, 'overtime_hours' => 0.01];
        $pending = Attendance::create($base + ['work_date' => '2026-11-18', 'overtime_status' => Attendance::OVERTIME_PENDING]);
        $approved = Attendance::create(array_merge($base, ['work_date' => '2026-11-17', 'clock_in_at' => '2026-11-17 06:56:00', 'clock_out_at' => '2026-11-17 15:00:40',
            'overtime_status' => Attendance::OVERTIME_APPROVED, 'overtime_approved_hours' => 0.01]));

        Artisan::call('attendance:recalculate', ['tenant' => (string) $acme->id, '--from' => '2026-11-01', '--apply' => true]);

        $pending->refresh();
        $this->assertEquals(0.0, (float) $pending->overtime_hours);
        $this->assertNull($pending->overtime_status);
        $this->assertSame(480, $pending->regularMinutes());   // 08:00:00 de jornada, no 07:59:00

        $approved->refresh();
        $this->assertSame(Attendance::OVERTIME_APPROVED, $approved->overtime_status);
        $this->assertEquals(0.01, (float) $approved->overtime_approved_hours);
    }
}
