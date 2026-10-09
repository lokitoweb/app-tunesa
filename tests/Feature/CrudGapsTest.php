<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Payslip;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Borrado de sucursales y eliminación / anulación de boletas desde el panel.
 */
class CrudGapsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('private');
    }

    public function test_branch_delete_is_blocked_while_it_has_active_employees(): void
    {
        $acme   = $this->makeTenant('acme');
        $hr     = $this->makeUser($acme, 'hr_manager');
        $branch = $this->makeBranch($acme, ['name' => 'Obra Norte']);
        $emp    = $this->makeUser($acme);
        $emp->branches()->attach($branch->id, ['is_primary' => true]);

        $this->actingAs($hr)->get('/branches')->assertOk()->assertSee('deleteBranchModal')->assertSee(route('branches.destroy', $branch->id));

        $this->delete("/branches/{$branch->id}")->assertRedirect(route('branches.index'))->assertSessionHas('error');
        $this->assertNotNull(Branch::find($branch->id));

        $emp->update(['status' => 'inactive']);
        $this->delete("/branches/{$branch->id}")->assertSessionHas('success');
        $this->assertNull(Branch::find($branch->id));
        $this->assertSame('branch.deleted', AuditLog::latest('id')->value('action'));
    }

    public function test_branch_delete_works_in_super_admin_global_view(): void
    {
        $acme   = $this->makeTenant('acme');
        $branch = $this->makeBranch($acme);

        $this->actingAs($this->makeUser(null, 'super_admin'))
            ->delete("/branches/{$branch->id}")->assertSessionHas('success');
        $this->assertNull(Branch::find($branch->id));
    }

    private function payslip(Tenant $tenant, User $emp, User $hr, string $status): Payslip
    {
        $path = "payslips/{$tenant->id}/" . uniqid() . '.pdf';
        Storage::disk('private')->put($path, '%PDF');

        return Payslip::create(['tenant_id' => $tenant->id, 'user_id' => $emp->id, 'uploaded_by' => $hr->id,
            'title' => 'Boleta', 'period_month' => 9, 'period_year' => 2026, 'file_path' => $path, 'file_name' => 'b.pdf',
            'status' => $status, 'published_at' => $status === 'published' ? now() : null]);
    }

    public function test_draft_payslip_is_deleted_and_published_one_is_annulled(): void
    {
        $acme = $this->makeTenant('acme');
        $hr   = $this->makeUser($acme, 'hr_manager');
        [$ana, $luis] = [$this->makeUser($acme), $this->makeUser($acme)];

        $draft     = $this->payslip($acme, $ana, $hr, 'draft');
        $published = $this->payslip($acme, $luis, $hr, 'published');

        $this->actingAs($hr)->get('/payslips?year=2026&month=9')->assertOk()
            ->assertSee(route('payslips.destroy', $draft->id))->assertSee(route('payslips.annul', $published->id));

        // Publicada: no se elimina, se anula
        $this->delete("/payslips/{$published->id}")->assertSessionHas('error');
        $this->post("/payslips/{$published->id}/annul")->assertSessionHas('success');
        $this->assertSame('archived', $published->fresh()->status);
        $this->get('/payslips?year=2026&month=9')->assertSee('Anulada');

        // El colaborador ya no la ve en la app
        $this->actingIn($luis, $acme)->getJson("/api/payslips/{$published->id}")->assertNotFound();

        // Borrador: se elimina con su PDF
        $this->app['auth']->forgetGuards();
        $this->actingAs($hr, 'web')->post("/payslips/{$draft->id}/annul")->assertSessionHas('error');
        $this->delete("/payslips/{$draft->id}")->assertSessionHas('success');
        $this->assertNull(Payslip::withTrashed()->find($draft->id));
        Storage::disk('private')->assertMissing($draft->file_path);
        $this->assertSame(['payslip.archived', 'payslip.deleted'], AuditLog::orderBy('id')->pluck('action')->all());
    }

    public function test_web_routes_with_id_only_accept_numbers(): void
    {
        $acme = $this->makeTenant('acme');
        $this->actingAs($this->makeUser($acme, 'company_admin'));

        foreach (['/employees/abc/edit', '/shifts/abc/edit', '/branches/1x/edit', '/payslips/abc/view', '/approvals/leave/abc/attachment'] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->delete('/payslips/abc')->assertNotFound();
    }

    public function test_cannot_touch_another_companys_payslip(): void
    {
        $acme  = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $hrO   = $this->makeUser($other, 'hr_manager');
        $foreign = $this->payslip($other, $this->makeUser($other), $hrO, 'draft');

        $this->actingAs($this->makeUser($acme, 'hr_manager'))->delete("/payslips/{$foreign->id}")->assertNotFound();
        $this->assertNotNull(Payslip::withoutGlobalScopes()->find($foreign->id));
    }
}
