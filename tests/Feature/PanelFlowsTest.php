<?php

namespace Tests\Feature;

use App\Models\CompanyConfiguration;
use App\Models\EmployeeShift;
use App\Models\Payslip;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Flujos de escritura del panel que la revisión módulo por módulo encontró rotos:
 * alta/edición de colaboradores, boletas y validaciones entre empresas.
 */
class PanelFlowsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('private');
    }

    private function makeShift($tenant): Shift
    {
        return Shift::create(['tenant_id' => $tenant->id, 'name' => 'Mañana', 'code' => 'M-' . $tenant->id,
            'start_time' => '08:00', 'end_time' => '17:00']);
    }

    public function test_hr_creates_and_edits_an_employee_with_branch_and_shift(): void
    {
        $acme   = $this->makeTenant('acme');
        $hr     = $this->makeUser($acme, 'hr_manager');
        $branch = $this->makeBranch($acme);
        $shift  = $this->makeShift($acme);
        $other  = $this->makeShift($acme);

        $this->actingAs($hr)->get('/employees/create')->assertOk();

        $this->post('/employees', [
            'first_name' => 'Rosa', 'last_name' => 'Quispe', 'email' => 'rosa@acme.pe', 'password' => 'Temporal123',
            'role' => 'employee', 'document_type' => 'dni', 'document_number' => '70112233',
            'branch_ids' => [$branch->id], 'primary_branch_id' => $branch->id, 'shift_id' => $shift->id,
        ])->assertRedirect(route('employees.index'))->assertSessionHasNoErrors();

        $rosa = User::where('email', 'rosa@acme.pe')->firstOrFail();
        $this->assertSame($acme->id, $rosa->tenant_id);
        $this->assertEquals(0, $rosa->base_salary);
        $this->assertTrue((bool) $rosa->branches()->first()->pivot->is_primary);
        $assignment = EmployeeShift::where('user_id', $rosa->id)->sole();
        $this->assertSame($branch->id, $assignment->branch_id);
        $this->assertSame(today()->toDateString(), $assignment->effective_from->toDateString());

        $this->get("/employees/{$rosa->id}/edit")->assertOk();
        $this->put("/employees/{$rosa->id}", [
            'first_name' => 'Rosa', 'last_name' => 'Quispe Díaz', 'email' => 'rosa@acme.pe', 'status' => 'active',
            'role' => 'employee', 'document_type' => 'ce', 'document_number' => '70112233',
            'branch_ids' => [$branch->id], 'primary_branch_id' => $branch->id, 'shift_id' => $other->id,
        ])->assertRedirect(route('employees.index'))->assertSessionHasNoErrors();

        $this->assertSame('Quispe Díaz', $rosa->fresh()->last_name);
        $this->assertSame($other->id, EmployeeShift::where('user_id', $rosa->id)->where('is_active', true)->sole()->shift_id);
    }

    public function test_employee_form_rejects_another_companys_branch_and_unknown_document_type(): void
    {
        $acme  = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $hr    = $this->makeUser($acme, 'hr_manager');
        $foreign = $this->makeBranch($other);

        $this->actingAs($hr)->post('/employees', [
            'first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@acme.pe', 'password' => 'Temporal123',
            'role' => 'employee', 'document_type' => 'RUT', 'document_number' => '1',
            'branch_ids' => [$foreign->id], 'primary_branch_id' => $foreign->id,
        ])->assertSessionHasErrors(['document_type', 'branch_ids.0']);

        $this->assertDatabaseMissing('users', ['email' => 'x@acme.pe']);
    }

    public function test_payslips_list_publish_and_tenant_checks(): void
    {
        $acme  = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $hr    = $this->makeUser($acme, 'hr_manager');
        $ana   = $this->makeUser($acme, 'employee', ['first_name' => 'Ana']);
        $ajeno = $this->makeUser($other);

        $upload = fn (User $user) => $this->actingAs($hr)->post('/payslips', [
            'user_id' => $user->id, 'title' => 'Boleta octubre', 'period_month' => 10, 'period_year' => 2026,
            'pdf_file' => UploadedFile::fake()->create('boleta.pdf', 40, 'application/pdf'),
            'gross_salary' => 2000, 'deductions' => 200, 'net_salary' => 1800,
        ]);

        $upload($ana)->assertSessionHasNoErrors();
        $upload($ana)->assertSessionHasErrors('user_id');      // mismo periodo: aviso, no error 500
        $upload($ajeno)->assertSessionHasErrors('user_id');    // colaborador de otra empresa
        $this->assertSame(1, Payslip::count());

        $this->get('/payslips?year=2026&month=10')->assertOk()->assertSee('Ana');

        $payslip = Payslip::sole();
        $this->from('/payslips')->post("/payslips/{$payslip->id}/publish")
            ->assertRedirect('/payslips')->assertSessionHas('success', 'Boleta publicada para Ana.');
        $this->assertSame('published', $payslip->fresh()->status);
    }

    public function test_branch_supervisor_must_belong_to_the_same_company(): void
    {
        $acme  = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $hr    = $this->makeUser($acme, 'hr_manager');
        $branch = $this->makeBranch($acme);
        $foreignSupervisor = $this->makeUser($other, 'supervisor');

        $this->actingAs($hr)->put("/branches/{$branch->id}", [
            'name' => $branch->name, 'address' => 'Av. Uno 123', 'latitude' => -12.05, 'longitude' => -77.04,
            'allowed_radius_meters' => 100, 'supervisor_id' => $foreignSupervisor->id,
        ])->assertSessionHasErrors('supervisor_id');

        $this->assertNull($branch->fresh()->supervisor_id);
    }

    public function test_payslips_api_respects_the_mobile_module_switch(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme);
        $hr   = $this->makeUser($acme, 'hr_manager');
        CompanyConfiguration::create(['tenant_id' => $acme->id, 'mobile_payslips_enabled' => false]);

        $this->actingIn($ana, $acme)->getJson('/api/payslips')->assertForbidden()->assertJson(['code' => 'MODULE_DISABLED']);
        $this->actingIn($hr, $acme)->getJson('/api/payslips')->assertOk();
    }

    public function test_global_view_employee_file_is_read_only(): void
    {
        $acme = $this->makeTenant('acme');
        $root = $this->makeUser(null, 'super_admin', ['first_name' => 'Root']);
        $ana  = $this->makeUser($acme);

        $this->actingAs($root)->get("/employees/{$ana->id}/file")->assertOk();
        $this->assertSame(0, CompanyConfiguration::withoutGlobalScopes()->count());

        // Los Super Admin no aparecen como colaboradores en la vista global
        $this->get('/employees')->assertOk()->assertViewHas('employees', fn ($rows) => $rows->pluck('id')->doesntContain($root->id));
    }

    public function test_global_view_shows_company_in_uniforms_and_payroll(): void
    {
        $acme  = $this->makeTenant('acme', ['company_name' => 'Acme SAC']);
        $other = $this->makeTenant('other', ['company_name' => 'Other SRL']);
        $root  = $this->makeUser(null, 'super_admin');
        $this->makeUser($acme, 'employee', ['first_name' => 'Lucia']);
        $this->makeUser($other, 'employee', ['first_name' => 'Mateo']);

        $this->actingAs($root);
        $this->get('/uniforms')->assertOk()->assertSee('Lucia')->assertSee('Mateo')->assertSee('Acme SAC')->assertSee('Other SRL');
        $this->get('/payroll')->assertOk()->assertSee('Acme SAC')->assertSee('Other SRL');

        $csv = $this->get('/payroll/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Empresa', strtok($csv, "\n"));
        $this->assertStringContainsString('Other SRL', $csv);
        $this->assertStringContainsString('Empresa', strtok($this->get('/uniforms/export')->streamedContent(), "\n"));
    }

    public function test_hr_duplicate_document_message_speaks_about_the_employee(): void
    {
        $acme = $this->makeTenant('acme');
        $hr   = $this->makeUser($acme, 'hr_manager');
        $ana  = $this->makeUser($acme);
        $pdf  = fn () => UploadedFile::fake()->createWithContent('dni.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

        $this->actingAs($hr)->post("/employees/{$ana->id}/file/documents", ['type' => 'dni', 'file' => $pdf()])->assertSessionHasNoErrors();
        $this->post("/employees/{$ana->id}/file/documents", ['type' => 'dni', 'file' => $pdf()])
            ->assertSessionHasErrors(['file' => 'Este archivo ya está en el legajo del colaborador.']);
    }
}
