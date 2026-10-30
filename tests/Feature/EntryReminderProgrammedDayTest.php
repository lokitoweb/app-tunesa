<?php

namespace Tests\Feature;

use App\Modules\Attendance\Notifications\EntryReminder;
use App\Modules\HR\Models\EmployeeScheduleDay;
use App\Modules\HR\Models\Shift;
use App\Modules\Integrations\Services\Push\FcmSender;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Recordatorio de entrada (5 min antes) para quien solo tiene el día programado en
 * «Programación de horarios», sin asignación semanal (caso reportado en producción).
 */
class EntryReminderProgrammedDayTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_programmed_day_without_weekly_assignment_gets_the_entry_reminder(): void
    {
        $this->seedRoles();
        Notification::fake();
        $this->mock(FcmSender::class)->shouldReceive('canSendTo')->andReturn(true);

        $acme   = $this->makeTenant('acme');
        $branch = $this->makeBranch($acme);
        $emp    = $this->makeUser($acme, 'employee', ['fcm_token' => 'token-de-prueba']);
        $emp->branches()->attach($branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
        $shift = Shift::create(['tenant_id' => $acme->id, 'branch_id' => $branch->id, 'name' => 'Mañana', 'type' => 'fixed',
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_period_minutes' => 10, 'working_days' => [1, 2, 3, 4, 5], 'color' => '#3C729E']);
        EmployeeScheduleDay::create(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'work_date' => '2026-11-02', 'shift_id' => $shift->id, 'branch_id' => $branch->id]);

        $this->travelTo(Carbon::parse('2026-11-02 07:54', 'America/Lima'));
        $this->artisan('attendance:remind-entry')->assertSuccessful();
        Notification::assertNotSentTo($emp, EntryReminder::class); // aún no: 6 min antes

        $this->travelTo(Carbon::parse('2026-11-02 07:55', 'America/Lima'));
        $this->artisan('attendance:remind-entry')->assertSuccessful();
        Notification::assertSentToTimes($emp, EntryReminder::class, 1);
    }

    /** Un permiso por horas que no cubre la entrada no exime del recordatorio; uno que la cubre o una licencia de día completo, sí */
    public function test_only_leaves_that_cover_the_entry_time_skip_the_reminder(): void
    {
        $this->seedRoles();
        Notification::fake();
        $this->mock(FcmSender::class)->shouldReceive('canSendTo')->andReturn(true);

        $acme   = $this->makeTenant('acme');
        $branch = $this->makeBranch($acme);
        $shift  = Shift::create(['tenant_id' => $acme->id, 'branch_id' => $branch->id, 'name' => 'Mañana', 'type' => 'fixed',
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_period_minutes' => 10, 'working_days' => [1, 2, 3, 4, 5], 'color' => '#3C729E']);
        $person = function (string $name, ?array $leave) use ($acme, $branch, $shift) {
            $u = $this->makeUser($acme, 'employee', ['first_name' => $name, 'fcm_token' => "token-{$name}"]);
            $u->branches()->attach($branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);
            EmployeeScheduleDay::create(['tenant_id' => $acme->id, 'user_id' => $u->id, 'work_date' => '2026-11-02', 'shift_id' => $shift->id, 'branch_id' => $branch->id]);
            if ($leave) {
                \App\Modules\HR\Models\LeaveRequest::create(['tenant_id' => $acme->id, 'user_id' => $u->id, 'start_date' => '2026-11-02', 'end_date' => '2026-11-02',
                    'days' => 1, 'reason' => 'Prueba', 'status' => 'approved'] + $leave);
            }

            return $u;
        };
        $laterPermit = $person('Tarde', ['type' => 'permission', 'start_time' => '10:00', 'end_time' => '12:00']);
        $entryPermit = $person('Entrada', ['type' => 'permission', 'start_time' => '08:00', 'end_time' => '10:00']);
        $vacation    = $person('Vacaciones', ['type' => 'vacation']);
        $nobody      = $person('Normal', null);

        $this->travelTo(Carbon::parse('2026-11-02 07:56', 'America/Lima'));
        $this->artisan('attendance:remind-entry')->assertSuccessful();

        Notification::assertSentTo($laterPermit, EntryReminder::class);  // su permiso es de 10:00 a 12:00: igual entra a las 08:00
        Notification::assertSentTo($nobody, EntryReminder::class);
        Notification::assertNotSentTo($entryPermit, EntryReminder::class);
        Notification::assertNotSentTo($vacation, EntryReminder::class);
    }
}
