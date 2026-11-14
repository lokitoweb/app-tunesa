<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Benefits\Models\Benefit;
use App\Modules\CompanyDocs\Models\CompanyDocument;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DataCleanup;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Limpieza pedida por el cliente (DataCleanup + migración de una sola vez): borra solo lo pedido,
 * con todo lo que cuelga de cada cosa (también sus archivos), y conserva lo demás.
 */
class DataCleanupTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_it_empties_only_what_the_client_asked_and_keeps_active_staff_and_everything_else(): void
    {
        Storage::fake('private');
        Storage::fake('public');
        $this->seedRoles();
        $acme  = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $branch = $this->makeBranch($acme);

        $active   = $this->makeUser($acme, 'employee', ['first_name' => 'Rosa', 'last_name' => 'Activa']);
        $inactive = $this->makeUser($acme, 'employee', ['first_name' => 'Luis', 'last_name' => 'Cesado', 'status' => 'inactive']);
        $inactiveOther = $this->makeUser($other, 'hr_manager', ['status' => 'inactive']);
        $juan     = $this->makeUser($acme, 'supervisor', ['first_name' => 'JUAN', 'last_name' => 'PÉREZ  QUISPE']);
        $otroJuan = $this->makeUser($acme, 'employee', ['first_name' => 'Juan', 'last_name' => 'Pérez Quispe']); // no es el supervisor de prueba
        $root     = $this->makeUser(null, 'super_admin', ['status' => 'inactive']);
        $branch->syncSupervisors([$juan->id]);

        // Asistencia: la del activo se queda; la del cesado se va con su selfie y su marcación
        $shift = Shift::create(['tenant_id' => $acme->id, 'name' => 'Mañana', 'start_time' => '08:00', 'end_time' => '17:00', 'working_days' => [1, 2, 3, 4, 5]]);
        EmployeeShift::create(['tenant_id' => $acme->id, 'user_id' => $active->id, 'shift_id' => $shift->id, 'branch_id' => $branch->id, 'effective_from' => '2026-01-01', 'is_active' => true]);
        $keep = Attendance::create(['tenant_id' => $acme->id, 'user_id' => $active->id, 'branch_id' => $branch->id, 'shift_id' => $shift->id, 'work_date' => '2026-11-10', 'status' => 'present']);
        Storage::disk('private')->put('attendance/cesado.webp', 'x');
        Storage::disk('private')->put('attendance/rosa.webp', 'x');
        Attendance::create(['tenant_id' => $acme->id, 'user_id' => $inactive->id, 'branch_id' => $branch->id, 'work_date' => '2026-11-10', 'status' => 'present', 'clock_in_photo' => 'attendance/cesado.webp']);
        $keep->update(['clock_in_photo' => 'attendance/rosa.webp']);

        // Secciones a vaciar
        DB::table('holidays')->insert(['tenant_id' => $acme->id, 'date' => '2026-12-25', 'name' => 'Navidad', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('attendance_offshift_authorizations')->insert(['tenant_id' => $acme->id, 'user_id' => $active->id, 'work_date' => '2026-11-15', 'reason' => 'Inventario', 'created_at' => now(), 'updated_at' => now()]);
        $caseId = DB::table('ethics_cases')->insertGetId(['tenant_id' => $acme->id, 'public_code' => 'ABC123', 'token_hash' => str_repeat('a', 64), 'category' => 'otro',
            'subject' => 'x', 'body' => 'x', 'reported_on' => '2026-11-01']);
        Storage::disk('private')->put('ethics/adjunto.pdf', 'x');
        DB::table('ethics_case_attachments')->insert(['tenant_id' => $acme->id, 'case_id' => $caseId, 'path' => 'ethics/adjunto.pdf', 'mime' => 'application/pdf', 'size' => 1]);
        DB::table('benefit_categories')->insert(['tenant_id' => $acme->id, 'name' => 'Salud', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_document_folders')->insert(['tenant_id' => $acme->id, 'name' => 'Reglamentos', 'created_at' => now(), 'updated_at' => now()]);
        Storage::disk('public')->put('benefits/promo.jpg', 'x');
        $benefitId = DB::table('benefits')->insertGetId(['tenant_id' => $acme->id, 'title' => 'Descuento', 'image_path' => 'benefits/promo.jpg', 'created_at' => now(), 'updated_at' => now()]);
        Storage::disk('private')->put('company-docs/reglamento.pdf', 'x');
        $docId = DB::table('company_documents')->insertGetId(['tenant_id' => $acme->id, 'title' => 'Reglamento', 'file_disk' => 'private', 'file_path' => 'company-docs/reglamento.pdf',
            'file_name' => 'reglamento.pdf', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_document_reads')->insert(['tenant_id' => $acme->id, 'document_id' => $docId, 'user_id' => $active->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[Benefit::class, $benefitId], [CompanyDocument::class, $docId], ['App\\Modules\\Announcements\\Models\\Announcement', 99]] as [$type, $id]) {
            DB::table('audience_targets')->insert(['tenant_id' => $acme->id, 'targetable_type' => $type, 'targetable_id' => $id, 'target_type' => 'all', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('training_courses')->insert(['tenant_id' => $acme->id, 'title' => 'Inducción', 'created_at' => now(), 'updated_at' => now()]);

        // Lo que se borraría, sin borrar nada
        $plan = app(DataCleanup::class)->plan();
        $this->assertSame(3, $plan['Colaboradores inactivos y usuario de prueba']);
        $this->assertSame(2, $plan['Horarios']); // el turno y la asignación semanal del activo
        $this->assertSame(1, $plan['Feriados']);
        $this->assertSame(1, User::whereKey($inactive->id)->count(), 'plan() no borra');

        // La migración de una sola vez lo ejecuta y lo anota en la bitácora
        (require base_path('app/Modules/Core/Database/Migrations/2026_11_17_000001_client_data_cleanup.php'))->up();

        $exists = fn (User $u) => DB::table('users')->where('id', $u->id)->exists();
        $this->assertFalse($exists($inactive));
        $this->assertFalse($exists($inactiveOther), 'en todas las empresas');
        $this->assertFalse($exists($juan));
        $this->assertTrue($exists($active));
        $this->assertTrue($exists($otroJuan), 'solo el supervisor de prueba');
        $this->assertTrue($exists($root), 'nunca el Super Admin');

        // Lo del cesado se fue en cascada; la asistencia del activo se queda (sin turno)
        $this->assertSame(0, DB::table('attendances')->where('user_id', $inactive->id)->count());
        $this->assertNull($keep->fresh()->shift_id);
        $this->assertSame(0, DB::table('branch_supervisors')->count());
        Storage::disk('private')->assertMissing('attendance/cesado.webp');
        Storage::disk('private')->assertExists('attendance/rosa.webp');

        foreach (['shifts', 'employee_shifts', 'employee_schedule_days', 'holidays', 'attendance_offshift_authorizations', 'ethics_cases',
            'ethics_case_attachments', 'benefits', 'company_documents', 'company_document_reads', 'training_courses'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} vacía");
        }
        Storage::disk('private')->assertMissing('ethics/adjunto.pdf');
        Storage::disk('public')->assertMissing('benefits/promo.jpg');
        Storage::disk('private')->assertMissing('company-docs/reglamento.pdf');

        // Los destinatarios de otros módulos (comunicados) se conservan
        $this->assertSame(['App\\Modules\\Announcements\\Models\\Announcement'], DB::table('audience_targets')->pluck('targetable_type')->all());
        $this->assertSame(1, DB::table('benefit_categories')->count(), 'las categorías (organización) se conservan');
        $this->assertSame(1, DB::table('company_document_folders')->count(), 'las carpetas (organización) se conservan');
        $this->assertSame(1, AuditLog::where('action', 'data.cleanup')->count());

        // Correr de nuevo no rompe nada (no queda nada que borrar)
        $this->assertSame(0, app(DataCleanup::class)->plan()['Colaboradores inactivos y usuario de prueba']);
    }
}
