<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Department;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Notifications\LeaveRequestReviewed;
use App\Modules\HR\Notifications\LeaveRequestSubmitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * DEF-03: vacaciones pedidas desde la app llegan a "Aprobaciones" del panel de
 * su jefe inmediato (jefe de área) y de RR. HH.; la respuesta avisa al colaborador.
 */
class LeaveApprovalFlowTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_vacation_from_the_app_reaches_area_head_and_hr_and_the_answer_notifies_the_employee(): void
    {
        $this->seedRoles();
        Notification::fake();

        $acme = $this->makeTenant('acme');
        $hr = $this->makeUser($acme, 'hr_manager');
        $head = $this->makeUser($acme, 'supervisor', ['first_name' => 'Rosa', 'last_name' => 'Jefa']);
        $otherSupervisor = $this->makeUser($acme, 'supervisor');
        $sales = Department::create(['tenant_id' => $acme->id, 'name' => 'Ventas', 'head_id' => $head->id, 'is_active' => true]);
        // Sin sede en común con la jefa: la ve por ser jefa de su área
        $emp = $this->makeUser($acme, 'employee', ['department_id' => $sales->id]);

        $created = $this->actingIn($emp, $acme)->postJson('/api/leave-requests', [
            'type' => 'vacation', 'start_date' => '2026-11-02', 'end_date' => '2026-11-06', 'reason' => 'Viaje familiar',
        ])->assertCreated()->assertJsonPath('data.reviewers', 'Rosa Jefa (jefe de área) o RR. HH.');
        $id = $created->json('data.id');

        Notification::assertSentTo([$head, $hr], LeaveRequestSubmitted::class);
        Notification::assertNotSentTo($otherSupervisor, LeaveRequestSubmitted::class);

        // Bandeja web de la jefa: la ve, con quién la revisa
        $this->actingAs($head)->get('/approvals?tab=leave')->assertOk()
            ->assertSee($emp->full_name)->assertSee('Revisa: Rosa Jefa (jefe de área) o RR. HH.');
        $this->actingAs($otherSupervisor)->get('/approvals?tab=leave')->assertOk()->assertDontSee($emp->full_name);
        $this->actingAs($otherSupervisor)->post("/approvals/leave/{$id}", ['decision' => 'approved'])->assertNotFound();

        // También en la app del supervisor (misma regla)
        $this->actingIn($head, $acme)->getJson('/api/approvals/leave-requests')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($head)->post("/approvals/leave/{$id}", ['decision' => 'approved'])->assertSessionHas('success');
        $this->assertSame('approved', LeaveRequest::find($id)->status);
        Notification::assertSentTo($emp, LeaveRequestReviewed::class);

        // Ya revisada no muestra a quién le toca
        $this->actingIn($emp, $acme)->getJson('/api/leave-requests')->assertJsonPath('data.0.reviewers', null);
    }

    public function test_without_area_head_the_branch_supervisor_or_hr_reviews(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $supervisor = $this->makeUser($acme, 'supervisor', ['first_name' => 'Luis', 'last_name' => 'Sede']);
        $emp = $this->makeUser($acme);
        $branch = $this->makeBranch($acme, ['supervisor_id' => $supervisor->id]);
        $emp->branches()->attach($branch->id, ['is_primary' => true, 'assigned_from' => '2026-01-01']);

        $this->actingIn($emp, $acme)->postJson('/api/leave-requests', [
            'type' => 'permission', 'start_date' => '2026-11-02', 'end_date' => '2026-11-02', 'reason' => 'Trámite',
        ])->assertCreated()->assertJsonPath('data.reviewers', 'Luis Sede (supervisor de sede) o RR. HH.');

        $alone = $this->makeUser($acme);
        $this->actingIn($alone, $acme)->postJson('/api/leave-requests', [
            'type' => 'permission', 'start_date' => '2026-11-03', 'end_date' => '2026-11-03', 'reason' => 'Trámite',
        ])->assertCreated()->assertJsonPath('data.reviewers', 'RR. HH.');
    }

    public function test_hr_sets_the_area_head_in_organization(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $head = $this->makeUser($acme, 'supervisor');
        $foreign = $this->makeUser($this->makeTenant('other'), 'supervisor');

        $this->actingAs($admin)->post('/organization/departments', ['name' => 'Caja', 'is_active' => true, 'head_id' => $head->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($head->id, Department::where('name', 'Caja')->value('head_id'));

        $this->actingAs($admin)->post('/organization/departments', ['name' => 'Almacén', 'is_active' => true, 'head_id' => $foreign->id])
            ->assertSessionHasErrors('head_id');
    }
}
