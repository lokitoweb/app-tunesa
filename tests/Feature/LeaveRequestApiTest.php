<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

class LeaveRequestApiTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('private');
    }

    public function test_employee_requests_and_supervisor_of_their_branch_approves(): void
    {
        $acme       = $this->makeTenant('acme');
        $supervisor = $this->makeUser($acme, 'supervisor');
        $outsider   = $this->makeUser($acme, 'supervisor');
        $emp        = $this->makeUser($acme);
        $branch     = $this->makeBranch($acme, ['supervisor_id' => $supervisor->id]);
        $emp->branches()->attach($branch->id, ['is_primary' => true, 'assigned_from' => '2026-01-01']);

        $id = $this->actingIn($emp, $acme)->postJson('/api/leave-requests', [
            'type' => 'vacation', 'start_date' => '2026-11-02', 'end_date' => '2026-11-06',
            'reason' => 'Viaje familiar',
        ])->assertCreated()->assertJsonPath('data.days', 5)->json('data.id');

        // Fechas cruzadas → 409
        $this->actingIn($emp, $acme)->postJson('/api/leave-requests', [
            'type' => 'permission', 'start_date' => '2026-11-04', 'end_date' => '2026-11-04', 'reason' => 'Trámite',
        ])->assertStatus(409);

        // Un supervisor de otra sede no la ve
        $this->actingIn($outsider, $acme)->postJson("/api/approvals/leave-requests/{$id}/approve")->assertNotFound();

        $this->actingIn($supervisor, $acme)->getJson('/api/approvals/leave-requests')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->actingIn($supervisor, $acme)->postJson("/api/approvals/leave-requests/{$id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');

        // El empleado no puede aprobar
        $this->actingIn($emp, $acme)->getJson('/api/approvals/leave-requests')->assertForbidden();
    }

    public function test_medical_leave_requires_attachment(): void
    {
        $acme = $this->makeTenant('acme');
        $emp  = $this->makeUser($acme);

        $this->actingIn($emp, $acme)->postJson('/api/leave-requests', [
            'type' => 'medical_leave', 'start_date' => '2026-11-02', 'end_date' => '2026-11-03', 'reason' => 'Gripe',
        ])->assertUnprocessable()->assertJsonValidationErrors('attachment');

        $this->actingIn($emp, $acme)->post('/api/leave-requests', [
            'type' => 'medical_leave', 'start_date' => '2026-11-02', 'end_date' => '2026-11-03', 'reason' => 'Gripe',
            'attachment' => UploadedFile::fake()->create('certificado.pdf', 100, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('data.has_attachment', true);

        $this->assertSame($acme->id, LeaveRequest::first()->tenant_id);
    }
}
