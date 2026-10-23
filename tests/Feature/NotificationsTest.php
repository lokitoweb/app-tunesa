<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\HR\Notifications\LeaveRequestReviewed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Notificaciones in-app (canal database): disparadores en API y panel,
 * contrato JSON de /api/notifications y campana del panel.
 */
class NotificationsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('private');
    }

    /** @return array{0: Tenant, 1: User, 2: User, 3: User} empresa, supervisor, RRHH, colaborador */
    private function team(): array
    {
        $acme       = $this->makeTenant('acme');
        $supervisor = $this->makeUser($acme, 'supervisor');
        $hr         = $this->makeUser($acme, 'hr_manager');
        $emp        = $this->makeUser($acme, 'employee', ['first_name' => 'Rosa']);
        $branch     = $this->makeBranch($acme, ['supervisor_id' => $supervisor->id]);
        $emp->branches()->attach($branch->id, ['is_primary' => true, 'assigned_from' => '2026-01-01']);

        return [$acme, $supervisor, $hr, $emp];
    }

    private function types(User $user): array
    {
        return $user->fresh()->notifications->pluck('data.type')->all();
    }

    public function test_leave_request_notifies_approvers_and_decision_notifies_employee_via_api(): void
    {
        [$acme, $supervisor, $hr, $emp] = $this->team();
        $otherSupervisor = $this->makeUser($acme, 'supervisor');

        $id = $this->actingIn($emp, $acme)->postJson('/api/leave-requests', [
            'type' => 'vacation', 'start_date' => '2026-11-02', 'end_date' => '2026-11-06', 'reason' => 'Viaje',
        ])->assertCreated()->json('data.id');

        $this->assertSame(['leave_request.submitted'], $this->types($supervisor));
        $this->assertSame(['leave_request.submitted'], $this->types($hr));
        $this->assertSame([], $this->types($otherSupervisor));
        $this->assertSame([], $this->types($emp));
        $this->assertStringContainsString('Rosa', $supervisor->fresh()->notifications->first()->data['body']);

        $this->actingIn($supervisor, $acme)->postJson("/api/approvals/leave-requests/{$id}/reject", ['notes' => 'Cierre de mes'])->assertOk();

        $n = $emp->fresh()->notifications->sole();
        $this->assertSame('leave_request.rejected', $n->data['type']);
        $this->assertSame('Solicitud rechazada', $n->data['title']);
        $this->assertSame(['leave_request_id' => $id, 'status' => 'rejected', 'screen' => 'leave_requests'], $n->data['data']);
    }

    public function test_leave_decision_from_web_notifies_and_mail_only_with_flag(): void
    {
        [$acme, $supervisor, $hr, $emp] = $this->team();
        $leave = LeaveRequest::create(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'type' => 'permission',
            'start_date' => '2026-11-02', 'end_date' => '2026-11-02', 'days' => 1, 'reason' => 'Trámite', 'status' => 'pending']);

        Notification::fake();
        config(['notifications.by_mail' => true]);

        $this->actingAs($hr)->post("/approvals/leave/{$leave->id}", ['decision' => 'approved'])->assertSessionHas('success');

        Notification::assertSentTo($emp, LeaveRequestReviewed::class, fn ($n, array $channels) => $channels === ['database', 'mail']);

        config(['notifications.by_mail' => false]);
        $this->assertSame(['database'], (new LeaveRequestReviewed($leave))->via($emp));
    }

    public function test_payslip_publication_notifies_on_web_api_and_bulk(): void
    {
        [$acme, , $hr, $emp] = $this->team();
        $other = $this->makeUser($acme, 'employee');

        $make = fn (User $u, int $month) => Payslip::create(['tenant_id' => $acme->id, 'user_id' => $u->id, 'uploaded_by' => $hr->id,
            'title' => 'Boleta', 'period_month' => $month, 'period_year' => 2026, 'file_path' => "p/{$u->id}-{$month}.pdf", 'file_name' => 'b.pdf']);

        $draft = $make($emp, 8);
        $this->assertSame([], $this->types($emp));

        // Panel: publicar una
        $this->actingAs($hr)->post("/payslips/{$draft->id}/publish")->assertSessionHas('success');
        $this->assertSame(['payslip.published'], $this->types($emp));

        // API: publicar
        $apiDraft = $make($emp, 7);
        $this->actingIn($hr, $acme)->postJson("/api/admin/payslips/{$apiDraft->id}/publish")->assertOk();
        $this->assertCount(2, $this->types($emp));

        // Panel: publicar todas las del periodo (una notificación por colaborador)
        $make($other, 9);
        $make($emp, 9);
        $this->actingAs($hr)->post('/payslips/bulk-publish', ['year' => 2026, 'month' => 9])->assertSessionHas('success');
        $this->assertSame(['payslip.published'], $this->types($other));
        $this->assertCount(3, $this->types($emp));

        // Carga masiva con "publicar ya"
        $emp->update(['document_number' => '45879632']);
        $this->actingAs($hr)->post('/payslips/bulk', [
            'period_month' => 10, 'period_year' => 2026, 'publish_now' => 1,
            'files' => [$this->fakePdf('boleta_45879632.pdf')],
        ])->assertSessionHas('success');
        $this->assertCount(4, $this->types($emp));
    }

    public function test_document_and_justification_reviews_notify_the_employee(): void
    {
        [$acme, , $hr, $emp] = $this->team();
        $branchId = $emp->branches()->value('branches.id');

        $doc = EmployeeDocument::create(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'uploaded_by' => $emp->id,
            'type' => 'dni', 'disk' => 'private', 'path' => 'd/x.pdf', 'original_name' => 'dni.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'status' => 'pending']);

        $this->actingIn($hr, $acme)->postJson("/api/admin/documents/{$doc->id}/reject", ['reason' => 'Ilegible'])->assertOk();
        $n = $emp->fresh()->notifications->sole();
        $this->assertSame('document.rejected', $n->data['type']);
        $this->assertStringContainsString('Ilegible', $n->data['body']);

        $att = Attendance::create(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'branch_id' => $branchId, 'work_date' => '2026-10-01',
            'status' => 'absent', 'justification_status' => 'pending']);

        $this->actingAs($hr)->post("/attendance/{$att->id}/review", ['justification_status' => 'approved'])->assertSessionHas('success');
        $n = $emp->fresh()->notifications->firstWhere('data.type', 'justification.approved');
        $this->assertSame('Justificación aprobada', $n->data['title']);

        $att2 = Attendance::create(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'branch_id' => $branchId, 'work_date' => '2026-10-02',
            'status' => 'absent', 'justification_status' => 'pending']);
        $this->actingIn($hr, $acme)->postJson("/api/admin/attendance/{$att2->id}/reject-justification", ['reason' => 'Sin sustento'])->assertOk();
        $this->assertContains('justification.rejected', $this->types($emp));
        $this->assertCount(3, $this->types($emp));
    }

    public function test_api_lists_marks_read_and_isolates_users(): void
    {
        [$acme, $supervisor, , $emp] = $this->team();
        $leave = LeaveRequest::create(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'type' => 'vacation',
            'start_date' => '2026-11-02', 'end_date' => '2026-11-03', 'days' => 2, 'reason' => 'x', 'status' => 'pending']);
        $leave->update(['status' => 'approved', 'reviewed_by' => $supervisor->id]);
        $leave->update(['status' => 'rejected']);

        $res = $this->actingIn($emp, $acme)->getJson('/api/notifications')->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [['id', 'type', 'title', 'body', 'data', 'read_at', 'created_at']],
                'meta' => ['current_page', 'last_page', 'total', 'unread_count'],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.unread_count', 2)
            ->assertJsonPath('data.0.type', fn ($t) => in_array($t, ['leave_request.approved', 'leave_request.rejected'], true))
            ->assertJsonPath('data.0.data.leave_request_id', $leave->id)
            ->assertJsonPath('data.0.read_at', null);

        $id = $res->json('data.0.id');

        // Otro usuario no puede marcar la notificación ajena
        $this->actingIn($supervisor, $acme)->postJson("/api/notifications/{$id}/read")->assertNotFound();

        $this->actingIn($emp, $acme)->postJson("/api/notifications/{$id}/read")
            ->assertOk()->assertJsonPath('meta.unread_count', 1)->assertJsonPath('data.id', $id);
        $this->assertNotNull($emp->notifications()->find($id)->read_at);

        $this->actingIn($emp, $acme)->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('meta.unread_count', 0);
        $this->actingIn($emp, $acme)->getJson('/api/notifications')->assertJsonPath('meta.unread_count', 0);
    }

    public function test_panel_bell_page_and_read_all(): void
    {
        [$acme, $supervisor, , $emp] = $this->team();
        LeaveRequest::create(['tenant_id' => $acme->id, 'user_id' => $emp->id, 'type' => 'vacation',
            'start_date' => '2026-11-02', 'end_date' => '2026-11-03', 'days' => 2, 'reason' => 'x', 'status' => 'pending']);

        // La campana del topbar lleva a la bandeja y muestra las no leídas
        $this->actingAs($supervisor)->get('/dashboard')->assertOk()
            ->assertSee(route('notifications.index'), false)->assertSee('<span class="badge-count badge-count-red">1</span>', false);
        $this->get('/notifications')->assertOk()->assertSee('Nueva solicitud por aprobar')->assertSee('Marcar todas como leídas');

        $n = $supervisor->notifications()->first();
        $this->post("/notifications/{$n->id}/read")->assertRedirect(route('approvals.index'));
        $this->assertNotNull($n->fresh()->read_at);

        $this->post('/notifications/read-all')->assertRedirect();
        $this->assertSame(0, $supervisor->unreadNotifications()->count());
    }

    public function test_super_admin_in_global_view_can_use_notifications(): void
    {
        $admin = $this->makeUser(null, 'super_admin');

        $this->actingAs($admin)->get('/notifications')->assertOk()->assertSee('No hay notificaciones en la bandeja');
        $this->post('/notifications/read-all')->assertSessionHas('success');
    }
}
