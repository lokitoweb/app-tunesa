<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Shift;
use App\Modules\Core\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Motor de jornada: jornada base de 8 h (4 h medio tiempo), descanso fijo de
 * 45 min descontado aunque no se marque, horas proporcionales y extra solo
 * desde la jornada base.
 */
class WorkdayEngineTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $acme;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('public');
        Storage::fake('private');
        $this->acme   = $this->makeTenant('acme');
        $this->branch = $this->makeBranch($this->acme);
    }

    private function employee(array $attrs = []): User
    {
        $user = $this->makeUser($this->acme, 'employee', $attrs);
        $user->branches()->attach($this->branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);

        return $user;
    }

    /** Entrada 08:00, descanso marcado opcional, salida tras $minutes de permanencia */
    private function workDay(User $user, int $minutes, int $markedBreak = 0, string $date = '2026-10-05'): Attendance
    {
        $gps = ['latitude' => -12.09418, 'longitude' => -77.03560, 'accuracy_meters' => 12, 'device_id' => 'test-device', 'device_os' => 'android'];

        $this->travelTo(Carbon::parse("{$date} 08:00:00", 'America/Lima'));
        $this->actingIn($user, $this->acme)->post('/api/attendance/clock-in', $gps + ['photo' => UploadedFile::fake()->image('in.jpg')])->assertCreated();
        if ($markedBreak) {
            $this->travel(240)->minutes();
            $this->actingIn($user, $this->acme)->postJson('/api/attendance/break-start', $gps)->assertOk();
            $this->travel($markedBreak)->minutes();
            $this->actingIn($user, $this->acme)->postJson('/api/attendance/break-end', $gps)->assertOk();
            $minutes -= 240 + $markedBreak;
        }
        $this->travel($minutes)->minutes();
        $this->actingIn($user, $this->acme)->post('/api/attendance/clock-out', $gps + ['photo' => UploadedFile::fake()->image('out.jpg')])->assertOk();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return Attendance::withoutGlobalScopes()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    public function test_full_time_without_shift_deducts_fixed_45_minute_break(): void
    {
        $this->assertSame(45, (int) TenantSetting::forTenant($this->acme)->lunch_minutes);

        // 08:00 a 16:45 sin marcar descanso: 8 h exactas, sin extra
        $day = $this->workDay($this->employee(), 8 * 60 + 45);
        $this->assertEquals(8.0, (float) $day->worked_hours);
        $this->assertEquals(0.0, (float) $day->overtime_hours);
        $this->assertNull($day->overtime_status);
    }

    public function test_four_punches_count_at_most_the_shift_hours_and_the_rest_is_overtime(): void
    {
        // 4 marcaciones (entrada, descanso, regreso, salida) y se queda 2 h más:
        // la jornada cuenta 8 h; las 2 h son extra y solo se pagan si se aprueban
        $day = $this->workDay($this->employee(), 10 * 60 + 60, markedBreak: 60);
        $this->assertEquals(8.0, $day->regularHours());
        $this->assertEquals(2.0, (float) $day->overtime_hours);
        $this->assertEquals(8.0, $day->payableHours());

        $day->update(['overtime_status' => Attendance::OVERTIME_APPROVED, 'overtime_approved_hours' => 1.5]);
        $this->assertEquals(9.5, $day->fresh()->payableHours());

        $this->actingIn($day->user, $this->acme)->getJson('/api/attendance/history?year=2026&month=10')
            ->assertOk()->assertJsonPath('summary.total_hours', 9.5)
            ->assertJsonPath('data.0.summary.regular_hours', 8);
    }

    public function test_longer_marked_break_is_deducted_instead_of_the_fixed_one(): void
    {
        // 9 h de permanencia con 60 min de descanso marcado: 8 h
        $day = $this->workDay($this->employee(), 9 * 60, markedBreak: 60);
        $this->assertEquals(8.0, (float) $day->worked_hours);
    }

    public function test_part_time_base_is_four_hours_and_short_days_have_no_fixed_break(): void
    {
        $part = $this->employee(['contract_type' => 'part_time']);

        // 4 h 30: menos de 5 h de permanencia, sin descanso fijo; 0.5 h extra por aprobar
        $day = $this->workDay($part, 4 * 60 + 30);
        $this->assertEquals(4.5, (float) $day->worked_hours);
        $this->assertEquals(0.5, (float) $day->overtime_hours);
        $this->assertSame(Attendance::OVERTIME_PENDING, $day->overtime_status);

        // Proporcional: 3 h trabajadas son 3 h (no la jornada completa)
        $short = $this->workDay($part, 3 * 60, date: '2026-10-06');
        $this->assertEquals(3.0, (float) $short->worked_hours);
        $this->assertEquals(0.0, (float) $short->overtime_hours);
    }

    public function test_shift_break_and_paid_lunch(): void
    {
        $emp   = $this->employee();
        $shift = Shift::create(['tenant_id' => $this->acme->id, 'name' => 'Oficina', 'start_time' => '08:00', 'end_time' => '17:30',
            'grace_period_minutes' => 10, 'break_allowed' => true, 'break_duration_minutes' => 45, 'daily_hours' => 8.75,
            'working_days' => [0, 1, 2, 3, 4, 5, 6]]);
        EmployeeShift::create(['tenant_id' => $this->acme->id, 'user_id' => $emp->id, 'shift_id' => $shift->id,
            'branch_id' => $this->branch->id, 'effective_from' => '2020-01-01', 'is_active' => true]);
        app(TenantContext::class)->clear();

        // 9 h 30 de permanencia - 45 min del turno = 8.75 h = jornada del turno
        $day = $this->workDay($emp, 9 * 60 + 30);
        $this->assertSame($shift->id, $day->shift_id);
        $this->assertEquals(8.75, (float) $day->worked_hours);
        $this->assertEquals(0.0, (float) $day->overtime_hours);

        // Refrigerio pagado: no se descuenta
        TenantSetting::editableRowFor($this->acme)->fill(['lunch_paid' => true])->save();
        $paid = $this->workDay($emp, 9 * 60 + 30, date: '2026-10-06');
        $this->assertEquals(9.5, (float) $paid->worked_hours);
    }
}
