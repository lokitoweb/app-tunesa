<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Services\AttendanceDataCleanup;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Limpieza de la asistencia de prueba (AttendanceDataCleanup + migración de una sola vez) */
class AttendanceDataCleanupTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_it_empties_attendance_and_requests_in_every_company_and_keeps_everything_else(): void
    {
        Storage::fake('private');
        Storage::fake('public');
        $this->seedRoles();
        $acme   = $this->makeTenant('acme');
        $other  = $this->makeTenant('other');
        $branch = $this->makeBranch($acme);
        $branch2 = $this->makeBranch($other);
        $emp    = $this->makeUser($acme, 'employee');
        $emp2   = $this->makeUser($other, 'employee');
        $shift  = Shift::create(['tenant_id' => $acme->id, 'name' => 'Mañana', 'start_time' => '08:00', 'end_time' => '17:00', 'working_days' => [1, 2, 3, 4, 5]]);

        Storage::disk('private')->put('attendance/in.webp', 'x');
        Storage::disk('private')->put('justifications/cert.pdf', 'x');
        Storage::disk('private')->put('leaves/descanso.pdf', 'x');
        $day = Attendance::create(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'branch_id' => $branch->id, 'shift_id' => $shift->id,
            'work_date' => '2026-11-10', 'status' => 'present', 'clock_in_photo' => 'attendance/in.webp', 'justification_file' => 'justifications/cert.pdf']);
        Attendance::create(['tenant_id' => $other->id, 'user_id' => $emp2->id, 'branch_id' => $branch2->id, 'work_date' => '2026-11-11', 'status' => 'absent']);
        Attendance::create(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'branch_id' => $branch->id, 'work_date' => '2026-11-09', 'status' => 'present'])->delete(); // en papelera
        AttendancePunch::create(['tenant_id' => $acme->id, 'attendance_id' => $day->id, 'user_id' => $emp->id, 'branch_id' => $branch->id,
            'type' => 'clock_in', 'client_uuid' => (string) Str::uuid(), 'latitude' => -12.0464, 'longitude' => -77.0428, 'within_geofence' => true,
            'device_time' => now(), 'server_time' => now(), 'offline' => false, 'photo_path' => 'attendance/in.webp']);
        DB::table('attendance_punch_attempts')->insert(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'type' => 'clock_in', 'error_code' => 'OUTSIDE_GEOFENCE', 'message' => 'x']);
        DB::table('attendance_contingencies')->insert(['tenant_id' => $acme->id, 'scope' => 'all', 'reason' => 'Prueba', 'starts_at' => now(), 'ends_at' => now()->addDay()]);
        DB::table('attendance_offshift_authorizations')->insert(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'work_date' => '2026-11-15', 'reason' => 'Inventario', 'created_at' => now(), 'updated_at' => now()]);
        LeaveRequest::create(['tenant_id' => $other->id, 'user_id' => $emp2->id, 'type' => 'vacation', 'start_date' => '2026-11-20', 'end_date' => '2026-11-21',
            'days' => 2, 'status' => 'pending', 'attachment_path' => 'leaves/descanso.pdf']);
        foreach (['attendance.outside_geofence', 'justification.approved', 'overtime.rejected', 'leave_request.submitted', 'shift.offshift_authorized', 'report.monthly_ready', 'document.reviewed'] as $type) {
            DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => $type, 'notifiable_type' => User::class, 'notifiable_id' => $emp->id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }

        $plan = app(AttendanceDataCleanup::class)->plan();
        $this->assertSame(3, $plan['Asistencia (jornadas y faltas)'], 'también la papelera, en todas las empresas');
        $this->assertSame(6, $plan['Avisos de asistencia y solicitudes']);
        $this->assertSame(3, Attendance::withTrashed()->count(), 'plan() no borra');

        (require base_path('app/Modules/Attendance/Database/Migrations/2026_11_21_000001_attendance_data_cleanup.php'))->up();

        foreach (array_merge(...array_values(AttendanceDataCleanup::TABLES)) as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} vacía");
        }
        Storage::disk('private')->assertMissing('attendance/in.webp');
        Storage::disk('private')->assertMissing('justifications/cert.pdf');
        Storage::disk('private')->assertMissing('leaves/descanso.pdf');

        // Lo demás se queda
        $this->assertSame(['document.reviewed'], DB::table('notifications')->pluck('type')->all());
        $this->assertTrue(User::whereKey([$emp->id, $emp2->id])->count() === 2);
        $this->assertSame(1, Shift::count());
        $this->assertSame(1, AuditLog::where('action', 'data.cleanup')->count());

        // Correr de nuevo no rompe nada ni anota otra vez
        (require base_path('app/Modules/Attendance/Database/Migrations/2026_11_21_000001_attendance_data_cleanup.php'))->up();
        $this->assertSame(1, AuditLog::where('action', 'data.cleanup')->count());
    }
}
