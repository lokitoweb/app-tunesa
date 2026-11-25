<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Services\TenantContext;
use App\Modules\HR\Models\EmployeeScheduleDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Tunesa empezó a usar el sistema el 16/11: lo cargado antes (días libres programados y faltas sin marcación)
 * se limpia para el cierre del mes, sin tocar nada real, con copia y bitácora.
 */
class PurgeBeforeGoLiveTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_setup_data_before_the_first_real_day_is_removed_with_a_copy(): void
    {
        Storage::fake('private');
        $this->seedRoles();
        $this->travelTo(Carbon::parse('2026-11-25 10:00:00', 'America/Lima'));
        $tunesa = $this->makeTenant('tunesaexpres');
        $other = $this->makeTenant('otra');
        app(TenantContext::class)->clear();
        $emp = $this->makeUser($tunesa, 'employee');
        $peer = $this->makeUser($other, 'employee');
        $branch = $this->makeBranch($tunesa);

        $day = fn ($user, string $date) => EmployeeScheduleDay::withoutGlobalScopes()->create(['tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'work_date' => $date, 'shift_id' => null, 'source' => 'calendar']);
        $att = fn ($user, string $date, array $extra = []) => Attendance::withoutGlobalScopes()->create($extra + ['tenant_id' => $user->tenant_id, 'user_id' => $user->id,
            'branch_id' => $user->tenant_id === $tunesa->id ? $branch->id : null, 'work_date' => $date, 'status' => 'absent']);

        $setupDay = $day($emp, '2026-11-14');
        $realDay = $day($emp, '2026-11-16');                       // desde el 16: real
        $otherDay = $day($peer, '2026-11-14');                     // otra empresa
        $setupAbsence = $att($emp, '2026-11-15');
        $punched = $att($emp, '2026-11-13', ['clock_in_at' => '2026-11-13 08:00:00', 'status' => 'present']);   // marcó: no se toca
        $manual = $att($emp, '2026-11-12', ['manual_edited_at' => now()]);                                       // corregida por RR. HH.
        $realAbsence = $att($emp, '2026-11-17');

        // Sin --apply solo lista
        $this->artisan('attendance:purge-before-go-live', ['tenant' => 'tunesaexpres', '--before' => '2026-11-16'])
            ->expectsOutputToContain('hay 1 días de programación y 1 faltas sin marcación. Se dejan 2 jornadas')->assertSuccessful();
        $this->assertNotNull(EmployeeScheduleDay::withoutGlobalScopes()->find($setupDay->id));

        $this->artisan('attendance:purge-before-go-live', ['tenant' => 'tunesaexpres', '--before' => '2026-11-16', '--apply' => true])
            ->expectsOutputToContain('Borrados: 1 días de programación y 1 faltas')->assertSuccessful();

        $this->assertNull(EmployeeScheduleDay::withoutGlobalScopes()->find($setupDay->id));
        $this->assertNull(Attendance::withoutGlobalScopes()->withTrashed()->find($setupAbsence->id));
        foreach ([$realDay, $otherDay] as $kept) {
            $this->assertNotNull(EmployeeScheduleDay::withoutGlobalScopes()->find($kept->id));
        }
        foreach ([$punched, $manual, $realAbsence] as $kept) {
            $this->assertNotNull(Attendance::withoutGlobalScopes()->find($kept->id));
        }

        // Copia completa y bitácora
        $files = Storage::disk('private')->allFiles('backups/purge-before-go-live');
        $this->assertCount(1, $files);
        $copy = json_decode(Storage::disk('private')->get($files[0]), true);
        $this->assertSame([$setupDay->id], array_column($copy['employee_schedule_days'], 'id'));
        $this->assertSame([$setupAbsence->id], array_column($copy['attendances'], 'id'));
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'schedule.pre_go_live_removed')->exists());
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'attendance.pre_go_live_removed')->exists());

        // Caso real (Sandy y Luz María, 15/11): falta con eventos de prueba en la bitácora de marcaciones y corregida.
        // Sin confirmarla se deja; confirmada con --attendance se quita con sus eventos, que van a la copia
        $tested = $att($emp, '2026-11-15', ['manual_edited_at' => now()]);
        foreach (['clock_in' => '15:20:41', 'clock_out' => '15:20:50'] as $type => $time) {
            \Illuminate\Support\Facades\DB::table('attendance_punches')->insert(['tenant_id' => $tunesa->id, 'attendance_id' => $tested->id,
                'user_id' => $emp->id, 'type' => $type, 'latitude' => 0, 'longitude' => 0, 'server_time' => "2026-11-15 {$time}"]);
        }
        $this->artisan('attendance:purge-before-go-live', ['tenant' => 'tunesaexpres', '--before' => '2026-11-16', '--apply' => true])
            ->expectsOutputToContain('hay 0 días de programación y 0 faltas sin marcación')->assertSuccessful();
        $this->assertNotNull(Attendance::withoutGlobalScopes()->find($tested->id));

        $this->artisan('attendance:purge-before-go-live', ['tenant' => 'tunesaexpres', '--before' => '2026-11-16', '--attendance' => (string) $tested->id, '--apply' => true])
            ->expectsOutputToContain('Borrados: 0 días de programación y 1 faltas')->assertSuccessful();
        $this->assertNull(Attendance::withoutGlobalScopes()->withTrashed()->find($tested->id));
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('attendance_punches')->where('attendance_id', $tested->id)->count());
        $copy = json_decode(Storage::disk('private')->get(collect(Storage::disk('private')->allFiles('backups/purge-before-go-live'))->sort()->last()), true);
        $this->assertSame([$tested->id], array_column($copy['attendances'], 'id'));
        $this->assertCount(2, $copy['attendance_punches']);
        // Lo real se mantiene
        $this->assertNotNull(Attendance::withoutGlobalScopes()->find($punched->id));
        $this->assertNotNull(Attendance::withoutGlobalScopes()->find($manual->id));

        // Otra instalación sin esa empresa: no hace nada
        $this->artisan('attendance:purge-before-go-live', ['tenant' => 'no-existe', '--before' => '2026-11-16', '--apply' => true])->assertSuccessful();
    }
}
