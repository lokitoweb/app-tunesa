<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\CompanyConfiguration;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Models\EmployeeProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Fase 3 — Panel web: visor de marcación, legajos, dotación, planilla y flags.
 */
class Phase3WebPanelTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('private');
        Storage::fake('public');
    }

    public function test_new_pages_render_for_admin_and_hr_and_are_closed_to_supervisors(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $hr    = $this->makeUser($acme, 'hr_manager');
        $sup   = $this->makeUser($acme, 'supervisor');
        $ana   = $this->makeUser($acme);
        EmployeeProfile::create(['tenant_id' => $acme->id, 'user_id' => $ana->id, 'bank_name' => 'BCP', 'bank_account_number' => '19112345678012']);

        foreach (['/documents', '/documents?status=all', "/employees/{$ana->id}/file", '/uniforms', '/payroll', '/payroll?year=2026&month=9', '/settings/mobile'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }

        $res = $this->actingAs($admin)->getJson('/payroll?year=2026&month=9');
        $res->assertOk()
            ->assertJsonStructure(['success', 'rows', 'totals', 'year', 'month', 'branchId'])
            ->assertJson(['success' => true, 'year' => 2026, 'month' => 9]);

        $this->actingAs($hr)->get("/employees/{$ana->id}/file")->assertOk()->assertDontSee('19112345678012');
        $this->actingAs($hr)->get('/settings/mobile')->assertForbidden();

        foreach (['/documents', '/uniforms', '/payroll', '/settings/mobile', "/employees/{$ana->id}/file"] as $url) {
            $this->actingAs($sup)->get($url)->assertForbidden();
        }
    }

    public function test_hr_reveals_full_account_only_on_request_and_it_is_audited(): void
    {
        $acme = $this->makeTenant('acme');
        $hr   = $this->makeUser($acme, 'hr_manager');
        $ana  = $this->makeUser($acme);
        EmployeeProfile::create(['tenant_id' => $acme->id, 'user_id' => $ana->id, 'bank_name' => 'BCP', 'bank_account_number' => '19112345678012']);

        $this->actingAs($hr)->from("/employees/{$ana->id}/file")->post("/employees/{$ana->id}/file/reveal")->assertRedirect();
        $this->actingAs($hr)->get("/employees/{$ana->id}/file")->assertSee('19112345678012');
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment_info.revealed', 'subject_id' => $ana->id]);
    }

    public function test_hr_cannot_open_the_file_of_a_higher_rank(): void
    {
        $acme  = $this->makeTenant('acme');
        $hr    = $this->makeUser($acme, 'hr_manager');
        $admin = $this->makeUser($acme, 'company_admin');

        $this->actingAs($hr)->get("/employees/{$admin->id}/file")->assertForbidden();
    }

    public function test_document_review_flow_in_the_panel(): void
    {
        $acme = $this->makeTenant('acme');
        $hr   = $this->makeUser($acme, 'hr_manager');
        $ana  = $this->makeUser($acme);

        $this->actingAs($hr)->post("/employees/{$ana->id}/file/documents", [
            'type' => 'contract', 'file' => UploadedFile::fake()->createWithContent('contrato.pdf', '%PDF-1.4 contrato'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $contract = EmployeeDocument::where('type', 'contract')->firstOrFail();
        $this->assertSame('approved', $contract->status, 'Lo que sube RRHH nace aprobado');

        $path = "documents/{$acme->id}/{$ana->id}/dni/x.jpg";
        Storage::disk('private')->put($path, 'jpg');
        $dni = EmployeeDocument::create([
            'tenant_id' => $acme->id, 'user_id' => $ana->id, 'type' => 'dni', 'disk' => 'private', 'path' => $path,
            'original_name' => 'dni.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 3, 'sha256' => hash('sha256', 'jpg'),
        ]);

        $this->actingAs($hr)->post("/documents/{$dni->id}/review", ['decision' => 'rejected'])->assertSessionHasErrors('reason');
        $this->actingAs($hr)->post("/documents/{$dni->id}/review", ['decision' => 'rejected', 'reason' => 'Foto borrosa'])->assertRedirect();
        $this->assertSame('rejected', $dni->fresh()->status);

        $this->actingAs($hr)->get("/documents/{$contract->id}/open")->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.viewed', 'subject_id' => $contract->id]);
    }

    public function test_mobile_visibility_switches_are_saved(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');

        $this->actingAs($admin)->put('/settings/mobile', [
            'mobile_payslips_enabled' => '1',
            'mobile_uniforms_enabled' => '1',
            'required_documents'      => ['dni', 'contract'],
            'max_upload_mb'           => 8,
        ])->assertRedirect();

        $config = CompanyConfiguration::where('tenant_id', $acme->id)->firstOrFail();
        $this->assertTrue($config->mobile_payslips_enabled);
        $this->assertFalse($config->mobile_payment_info_edit);
        $this->assertFalse($config->mobile_documents_enabled);
        $this->assertSame(['dni', 'contract'], $config->required_documents);
        $this->assertSame(8, $config->max_upload_mb);
    }

    public function test_attendance_evidence_returns_signed_selfie_and_geofence_comparison(): void
    {
        $acme   = $this->makeTenant('acme');
        $hr     = $this->makeUser($acme, 'hr_manager');
        $ana    = $this->makeUser($acme);
        $branch = $this->makeBranch($acme, ['allowed_radius_meters' => 100]);
        Storage::disk('private')->put('photos/selfie.jpg', 'jpg');

        $attendance = Attendance::create([
            'tenant_id' => $acme->id, 'user_id' => $ana->id, 'branch_id' => $branch->id, 'work_date' => today(),
            'clock_in_at' => now()->setTime(8, 2, 15), 'clock_in_latitude' => -12.0942, 'clock_in_longitude' => -77.0356,
            'clock_in_distance_meters' => 40, 'clock_in_within_geofence' => true, 'clock_in_photo' => 'photos/selfie.jpg',
            'clock_in_meta' => ['accuracy_meters' => 12, 'is_mocked' => false], 'status' => 'present',
        ]);

        $res = $this->actingAs($hr)->getJson("/attendance/{$attendance->id}/evidence")
            ->assertOk()
            ->assertJsonPath('clock_in.time', '08:02:15')
            ->assertJsonPath('clock_in.radius_usage', 40)
            ->assertJsonPath('clock_in.excess_meters', 0)
            ->assertJsonPath('clock_in.gps_accuracy', 12)
            ->assertJsonPath('branch.radius', 100);

        $url = $res->json('clock_in.photo_url');
        $this->get($url)->assertOk();
        $this->get('/attendance')->assertOk()->assertSee('openEvidence(', false);
    }

    public function test_payroll_csv_has_full_accounts_and_attendance_totals(): void
    {
        $acme = $this->makeTenant('acme');
        $hr   = $this->makeUser($acme, 'hr_manager');
        $ana  = $this->makeUser($acme, 'employee', ['document_number' => '45879632']);
        $branch = $this->makeBranch($acme);
        EmployeeProfile::create([
            'tenant_id' => $acme->id, 'user_id' => $ana->id, 'bank_name' => 'BCP', 'bank_account_number' => '19112345678012',
            'pension_system' => 'afp', 'pension_fund' => 'AFP Prima', 'labor_regime' => 'general',
        ]);
        Attendance::create([
            'tenant_id' => $acme->id, 'user_id' => $ana->id, 'branch_id' => $branch->id, 'work_date' => '2026-09-10', 'clock_in_at' => '2026-09-10 08:20:00',
            'status' => 'late', 'late_minutes' => 20, 'worked_hours' => 7.5,
        ]);
        Attendance::create(['tenant_id' => $acme->id, 'user_id' => $ana->id, 'branch_id' => $branch->id, 'work_date' => '2026-09-11', 'status' => 'absent']);

        $csv = $this->actingAs($hr)->get('/payroll/export?year=2026&month=9')->assertOk()->streamedContent();

        $this->assertStringContainsString('"=""19112345678012"""', $csv, 'La cuenta va como texto para Excel');
        $this->assertStringContainsString('AFP Prima', $csv);
        $this->assertStringContainsString('Régimen general', $csv);
        $line = collect(explode("\n", $csv))->first(fn ($l) => str_contains($l, '45879632'));
        $this->assertStringContainsString(',1,7.5,0,0,7.5,1,20,1,0,', $line, 'horas extra aprobadas, por aprobar y a pagar');
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.exported']);
    }

    public function test_admin_updates_employee_profile_via_ajax_and_modal_renders_executive_button(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $ana   = $this->makeUser($acme);
        EmployeeProfile::create(['tenant_id' => $acme->id, 'user_id' => $ana->id]);

        // 1. Modal renders with executive button
        $this->actingAs($admin)
            ->get("/employees/{$ana->id}/file", ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('legajo-btn-primary')
            ->assertSee('Modificar Ficha')
            ->assertSee('window.toggleEmployeeProfileEdit()', false);

        // 2. Profile update via AJAX
        $res = $this->actingAs($admin)->putJson("/employees/{$ana->id}/file/profile", [
            'bank_name' => 'BCP',
            'bank_account_type' => 'payroll',
            'bank_account_currency' => 'PEN',
            'bank_account_number' => '19112345678012',
            'labor_regime' => 'general',
            'pension_system' => 'afp',
            'pension_fund' => 'AFP Integra',
            'pension_code' => '612345AT0EA1',
            'address' => 'Av. Arequipa 1234',
            'district' => 'Lince',
            'emergency_contact_name' => 'Carmen Torres',
            'emergency_contact_phone' => '987654321',
        ]);

        $res->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('employee_profiles', [
            'user_id' => $ana->id,
            'bank_name' => 'BCP',
            'bank_account_type' => 'payroll',
            'district' => 'Lince',
            'emergency_contact_name' => 'Carmen Torres',
        ]);

        $this->assertSame('612345AT0EA1', $ana->fresh()->profile->pension_code);
        $this->assertSame('19112345678012', $ana->fresh()->profile->bank_account_number);
    }
}
