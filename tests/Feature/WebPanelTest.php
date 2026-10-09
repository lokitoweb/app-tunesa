<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\EmployeeShift;
use App\Models\LeaveRequest;
use App\Models\Payslip;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

class WebPanelTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('private');
    }

    public function test_login_and_every_panel_page_renders_for_hr(): void
    {
        $acme = $this->makeTenant('acme');
        $this->makeUser($acme, 'hr_manager', ['email' => 'rrhh@acme.pe', 'password' => 'Secret123']);
        $this->makeBranch($acme);

        $this->post('/login', ['email' => 'rrhh@acme.pe', 'password' => 'Secret123'])
            ->assertRedirect(route('dashboard'));

        foreach (['/dashboard', '/dashboard/live-stream', '/employees', '/shifts', '/shifts/create', '/attendance',
            '/approvals', '/approvals?tab=justifications', '/branches', '/payslips', '/payslips/bulk', '/reports'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_employees_cannot_enter_the_panel_and_supervisors_see_only_their_modules(): void
    {
        $acme = $this->makeTenant('acme');
        $this->makeUser($acme, 'employee', ['email' => 'emp@acme.pe', 'password' => 'Secret123']);
        $supervisor = $this->makeUser($acme, 'supervisor');

        $this->post('/login', ['email' => 'emp@acme.pe', 'password' => 'Secret123'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($supervisor)->get('/approvals')->assertOk();
        $this->actingAs($supervisor)->get('/payslips')->assertForbidden();
        $this->actingAs($supervisor)->get('/shifts')->assertForbidden();
    }

    public function test_web_queries_are_scoped_to_the_users_tenant(): void
    {
        $acme  = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $foreignShift = Shift::create(['tenant_id' => $other->id, 'name' => 'Ajeno', 'start_time' => '08:00', 'end_time' => '17:00', 'working_days' => [1]]);

        $this->actingAs($this->makeUser($acme, 'company_admin'))
            ->get("/shifts/{$foreignShift->id}/edit")
            ->assertNotFound();
    }

    public function test_hr_creates_a_rotating_shift_and_assigns_it(): void
    {
        $acme   = $this->makeTenant('acme');
        $hr     = $this->makeUser($acme, 'hr_manager');
        $emp    = $this->makeUser($acme);
        $branch = $this->makeBranch($acme);

        $this->actingAs($hr)->post('/shifts', [
            'name' => 'Mina 14x7', 'type' => 'rotating', 'start_time' => '07:00', 'end_time' => '19:00',
            'grace_period_minutes' => 5, 'break_duration_minutes' => 60, 'daily_hours' => 11, 'color' => '#10B981',
            'rotation_work_days' => 14, 'rotation_rest_days' => 7, 'is_active' => 1, 'break_allowed' => 1,
        ])->assertRedirect(route('shifts.index'));

        $shift = Shift::where('name', 'Mina 14x7')->firstOrFail();
        $this->assertSame($acme->id, $shift->tenant_id);
        $this->assertSame(14, $shift->rotation_work_days);

        $this->actingAs($hr)->post('/shifts/assign', [
            'shift_id' => $shift->id, 'branch_id' => $branch->id, 'user_ids' => [$emp->id],
            'effective_from' => '2026-10-01',
        ])->assertSessionHas('success');

        $assignment = EmployeeShift::where('user_id', $emp->id)->firstOrFail();
        $this->assertSame('2026-10-01', $assignment->rotation_anchor_date->toDateString());
    }

    public function test_bulk_payslip_upload_matches_by_document_or_code(): void
    {
        $acme = $this->makeTenant('acme');
        $hr   = $this->makeUser($acme, 'hr_manager');
        $ana  = $this->makeUser($acme, 'employee', ['document_number' => '45871236']);
        $luis = $this->makeUser($acme, 'employee', ['employee_code' => 'EMP-004']);

        $this->actingAs($hr)->post('/payslips/bulk', [
            'period_month' => 9, 'period_year' => 2026, 'publish_now' => 1,
            'files' => [
                UploadedFile::fake()->create('boleta_45871236.pdf', 50, 'application/pdf'),
                UploadedFile::fake()->create('EMP-004_setiembre.pdf', 50, 'application/pdf'),
                UploadedFile::fake()->create('desconocido_999.pdf', 50, 'application/pdf'),
            ],
        ])->assertRedirect(route('payslips.bulk'));

        $this->assertSame(2, Payslip::where('period_month', 9)->where('status', 'published')->count());
        $this->assertTrue(Payslip::where('user_id', $ana->id)->exists());
        $this->assertTrue(Payslip::where('user_id', $luis->id)->exists());
        $this->assertCount(1, session('import_result')['skipped']);
    }

    public function test_leave_and_justification_approval_flow(): void
    {
        $acme = $this->makeTenant('acme');
        $hr   = $this->makeUser($acme, 'hr_manager');
        $emp  = $this->makeUser($acme);
        $branch = $this->makeBranch($acme);

        $leave = LeaveRequest::create([
            'tenant_id' => $acme->id, 'user_id' => $emp->id, 'type' => 'vacation',
            'start_date' => '2026-11-02', 'end_date' => '2026-11-06', 'days' => 5, 'reason' => 'Viaje',
        ]);

        $this->actingAs($hr)->post("/approvals/leave/{$leave->id}", ['decision' => 'rejected'])
            ->assertSessionHasErrors('notes');
        $this->actingAs($hr)->post("/approvals/leave/{$leave->id}", ['decision' => 'approved'])
            ->assertSessionHas('success');
        $this->assertSame('approved', $leave->fresh()->status);

        $att = Attendance::create([
            'tenant_id' => $acme->id, 'user_id' => $emp->id, 'branch_id' => $branch->id, 'work_date' => '2026-10-07',
            'status' => 'late', 'late_minutes' => 20, 'justification_status' => 'pending', 'notes' => 'Tráfico',
        ]);

        $this->actingAs($hr)->post("/attendance/{$att->id}/review", ['justification_status' => 'approved'])
            ->assertSessionHas('success');
        $this->assertSame('excused', $att->fresh()->status);
    }

    public function test_mark_absences_command(): void
    {
        $acme   = $this->makeTenant('acme');
        $branch = $this->makeBranch($acme);
        $shift  = Shift::create(['tenant_id' => $acme->id, 'name' => 'L-V', 'start_time' => '08:00', 'end_time' => '17:00', 'working_days' => [1, 2, 3, 4, 5]]);

        [$absentee, $onLeave, $present] = [$this->makeUser($acme), $this->makeUser($acme), $this->makeUser($acme)];
        foreach ([$absentee, $onLeave, $present] as $u) {
            EmployeeShift::create(['tenant_id' => $acme->id, 'user_id' => $u->id, 'shift_id' => $shift->id,
                'branch_id' => $branch->id, 'effective_from' => '2026-01-01', 'is_active' => true]);
        }
        LeaveRequest::create(['tenant_id' => $acme->id, 'user_id' => $onLeave->id, 'type' => 'medical_leave',
            'start_date' => '2026-10-07', 'end_date' => '2026-10-09', 'status' => 'approved']);
        Attendance::create(['tenant_id' => $acme->id, 'user_id' => $present->id, 'branch_id' => $branch->id,
            'work_date' => '2026-10-08', 'clock_in_at' => now(), 'status' => 'present']);

        // 2026-10-08 es jueves; 2026-10-10 es sábado (no laborable)
        $this->artisan('attendance:mark-absences', ['--date' => '2026-10-08'])->assertSuccessful();
        $this->artisan('attendance:mark-absences', ['--date' => '2026-10-08'])->assertSuccessful(); // idempotente
        $this->artisan('attendance:mark-absences', ['--date' => '2026-10-10'])->assertSuccessful();

        $this->assertSame('absent', Attendance::where('user_id', $absentee->id)->sole()->status);
        $this->assertSame('excused', Attendance::where('user_id', $onLeave->id)->sole()->status);
        $this->assertSame(3, Attendance::count());
    }

    public function test_super_admin_without_tenant_sees_global_dashboard_with_every_company(): void
    {
        $acme  = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $this->makeUser(null, 'super_admin', ['email' => 'root@tunesa.app', 'password' => 'Secret123']);
        $acmeEmp = $this->makeUser($acme);
        $this->makeUser($other);
        $acmeBranch = $this->makeBranch($acme, ['name' => 'Sede Acme']);
        $this->makeBranch($other, ['name' => 'Sede Other']);
        Attendance::create([
            'tenant_id' => $acme->id, 'user_id' => $acmeEmp->id, 'branch_id' => $acmeBranch->id,
            'work_date' => today()->toDateString(), 'clock_in_at' => now(), 'status' => 'present',
        ]);

        $this->post('/login', ['email' => 'root@tunesa.app', 'password' => 'Secret123'])
            ->assertRedirect(route('dashboard'));

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Vista global: mostrando registros de todas las empresas')
            ->assertViewHas('kpis', fn ($kpis) => $kpis['total_employees'] === 2)
            ->assertViewHas('branches', fn ($branches) => $branches->count() === 2)
            ->assertSee('Sede Acme')
            ->assertSee('Sede Other');

        $this->getJson('/dashboard/live-stream')->assertOk()->assertJsonCount(1, 'attendances');
    }

    public function test_super_admin_without_tenant_opens_every_module_without_redirect(): void
    {
        $acme  = $this->makeTenant('acme', ['company_name' => 'Acme SAC']);
        $other = $this->makeTenant('other', ['company_name' => 'Other SRL']);
        $root  = $this->makeUser(null, 'super_admin');
        $acmeEmp  = $this->makeUser($acme, 'employee', ['first_name' => 'Lucia']);
        $otherEmp = $this->makeUser($other, 'employee', ['first_name' => 'Mateo']);
        $acmeBranch  = $this->makeBranch($acme, ['name' => 'Sede Acme']);
        $otherBranch = $this->makeBranch($other, ['name' => 'Sede Other']);
        $acmeAtt = null;
        foreach ([[$acme, $acmeEmp, $acmeBranch], [$other, $otherEmp, $otherBranch]] as [$tenant, $emp, $branch]) {
            $att = Attendance::create([
                'tenant_id' => $tenant->id, 'user_id' => $emp->id, 'branch_id' => $branch->id,
                'work_date' => today()->toDateString(), 'clock_in_at' => now(), 'status' => 'present',
            ]);
            $acmeAtt ??= $att;
            Shift::create(['tenant_id' => $tenant->id, 'name' => 'Turno ' . $tenant->slug, 'code' => 'T-' . $tenant->id,
                'start_time' => '08:00', 'end_time' => '17:00']);
        }

        $this->actingAs($root);

        // Lo que pide la directiva: 200 y nunca 302 al dashboard
        foreach (['/attendance', '/employees', '/shifts', '/branches', '/payslips'] as $url) {
            $this->get($url)->assertOk()->assertSee('Vista global: mostrando registros de todas las empresas');
        }

        // El resto de módulos tampoco redirige
        foreach (['/dashboard', '/approvals', '/approvals?tab=justifications', '/documents', '/uniforms', '/payroll',
            '/reports', '/reports/export/pdf', '/settings/mobile', "/attendance/{$acmeAtt->id}",
            "/employees/{$acmeEmp->id}/file"] as $url) {
            $this->get($url)->assertOk();
        }

        // Los listados muestran registros de todas las empresas con su nombre
        $this->get('/employees')->assertSee('Lucia')->assertSee('Mateo')->assertSee('Acme SAC')->assertSee('Other SRL');
        $this->get('/attendance')->assertSee('Lucia')->assertSee('Mateo');
        $this->get('/branches')->assertSee('Sede Acme')->assertSee('Sede Other');
        $this->get('/shifts')->assertSee('Turno acme')->assertSee('Turno other');

        // Crear o editar sí necesita empresa: vuelve al listado del módulo, no al dashboard
        $this->from('/secure-files/selfie-in/1')->get('/employees/create')->assertRedirect(route('employees.index'))->assertSessionHas('error');
        $this->post('/branches', ['name' => 'Sin empresa'])->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseMissing('branches', ['name' => 'Sin empresa']);
    }

    public function test_super_admin_can_switch_into_a_tenant_and_back(): void
    {
        $acme  = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $root  = $this->makeUser(null, 'super_admin');
        $this->makeUser($acme, 'employee', ['first_name' => 'Lucia']);
        $this->makeUser($other, 'employee', ['first_name' => 'Ajena']);
        $this->makeBranch($acme, ['name' => 'Sede Acme']);
        $this->makeBranch($other, ['name' => 'Sede Ajena']);

        $this->actingAs($root)->post('/admin/switch-tenant', ['tenant_id' => $acme->id])
            ->assertRedirect(route('dashboard'));

        $this->get('/dashboard')
            ->assertOk()
            ->assertViewHas('kpis', fn ($kpis) => $kpis['total_employees'] === 1)
            ->assertSee('Sede Acme')
            ->assertDontSee('Sede Ajena');

        $this->get('/employees')->assertOk()->assertSee('Lucia')->assertDontSee('Ajena');

        $this->post('/admin/switch-tenant', ['tenant_id' => null])->assertRedirect(route('dashboard'));
        $this->get('/employees')->assertOk()->assertSee('Lucia')->assertSee('Ajena');
    }

    public function test_company_admin_dashboard_only_counts_its_own_tenant(): void
    {
        $acme  = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $this->makeUser($acme, 'company_admin', ['email' => 'admin@acme.pe', 'password' => 'Secret123']);
        $acmeBranch  = $this->makeBranch($acme, ['name' => 'Sede Acme']);
        $otherBranch = $this->makeBranch($other, ['name' => 'Sede Ajena']);
        $acmeEmp  = $this->makeUser($acme);
        $otherEmp = $this->makeUser($other);
        $this->makeUser($other);

        foreach ([[$acme, $acmeEmp, $acmeBranch], [$other, $otherEmp, $otherBranch]] as [$tenant, $emp, $branch]) {
            Attendance::create([
                'tenant_id' => $tenant->id, 'user_id' => $emp->id, 'branch_id' => $branch->id,
                'work_date' => today()->toDateString(), 'clock_in_at' => now(), 'status' => 'present',
            ]);
        }

        $this->post('/login', ['email' => 'admin@acme.pe', 'password' => 'Secret123'])
            ->assertRedirect(route('dashboard'));

        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Vista global')
            ->assertViewHas('kpis', fn ($kpis) => $kpis['total_employees'] === 1 && $kpis['present_count'] === 1)
            ->assertViewHas('recentAttendances', fn ($rows) => $rows->pluck('tenant_id')->unique()->all() === [$acme->id])
            ->assertSee('Sede Acme')
            ->assertDontSee('Sede Ajena');

        // Un admin de empresa no puede cambiarse de empresa
        $this->post('/admin/switch-tenant', ['tenant_id' => $other->id])->assertForbidden();
    }
}
