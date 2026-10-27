<?php

namespace Tests\Feature;

use App\Modules\Payroll\Models\Payslip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

class PayslipApiTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('private');
    }

    private function payslipFor($tenant, $employee, $uploader, string $status = 'published', int $month = 9): Payslip
    {
        Storage::disk('private')->put("payslips/{$employee->id}.pdf", '%PDF-1.4 demo');

        return Payslip::create([
            'tenant_id' => $tenant->id, 'user_id' => $employee->id, 'uploaded_by' => $uploader->id,
            'title' => 'Boleta', 'period_month' => $month, 'period_year' => 2026,
            'file_path' => "payslips/{$employee->id}.pdf", 'file_name' => 'b.pdf', 'status' => $status,
        ]);
    }

    public function test_download_is_limited_to_the_owner(): void
    {
        $acme  = $this->makeTenant('acme');
        $hr    = $this->makeUser($acme, 'hr_manager');
        $ana   = $this->makeUser($acme);
        $luis  = $this->makeUser($acme);
        $slip  = $this->payslipFor($acme, $ana, $hr);

        $this->actingIn($ana, $acme)->get("/api/payslips/{$slip->id}/download")
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertNotNull($slip->fresh()->read_at);

        $this->actingIn($luis, $acme)->get("/api/payslips/{$slip->id}/download")->assertNotFound();
        $this->actingIn($hr, $acme)->get("/api/payslips/{$slip->id}/download")->assertOk();
    }

    public function test_supervisors_and_managers_only_see_their_own_payslip_detail(): void
    {
        $acme = $this->makeTenant('acme');
        $hr   = $this->makeUser($acme, 'hr_manager');
        $ana  = $this->makeUser($acme);
        $slip = $this->payslipFor($acme, $ana, $hr);
        $draft = $this->payslipFor($acme, $ana, $hr, 'draft', 10);

        // Antes: cualquier rol que no fuera "employee" veía sueldos ajenos (y borradores)
        foreach (['supervisor', 'manager', 'logistics'] as $role) {
            $boss = $this->makeUser($acme, $role);
            $this->actingIn($boss, $acme)->getJson("/api/payslips/{$slip->id}")->assertNotFound();
            $this->actingIn($boss, $acme)->getJson("/api/payslips/{$draft->id}")->assertNotFound();
        }

        $this->actingIn($ana, $acme)->getJson("/api/payslips/{$slip->id}")->assertOk();
        $this->actingIn($ana, $acme)->getJson("/api/payslips/{$draft->id}")->assertNotFound();
        $this->actingIn($hr, $acme)->getJson("/api/payslips/{$draft->id}")->assertOk();
        $this->assertNull($draft->fresh()->read_at, 'RR. HH. no marca como leída la boleta de otro');
    }

    public function test_hr_updates_and_deletes_drafts_but_not_published(): void
    {
        $acme  = $this->makeTenant('acme');
        $hr    = $this->makeUser($acme, 'hr_manager');
        $ana   = $this->makeUser($acme);
        $draft = $this->payslipFor($acme, $ana, $hr, 'draft');

        $this->actingIn($hr, $acme)->putJson("/api/admin/payslips/{$draft->id}", ['net_salary' => 1800])
            ->assertOk()->assertJsonPath('data.financials.net_salary', '1800.00');

        $draft->update(['status' => 'published']);
        $this->actingIn($hr, $acme)->deleteJson("/api/admin/payslips/{$draft->id}")->assertStatus(409);

        $draft->update(['status' => 'draft']);
        $this->actingIn($hr, $acme)->deleteJson("/api/admin/payslips/{$draft->id}")->assertOk();
        $this->assertNull(Payslip::withTrashed()->find($draft->id));
    }
}
