<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** La API y el panel revisan justificaciones con la misma regla (Attendance::reviewJustification) */
class JustificationReviewTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_api_review_keeps_the_employee_text_and_only_reviews_pending_ones(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $hr   = $this->makeUser($acme, 'hr_manager');
        $emp  = $this->makeUser($acme);
        $make = fn (string $date) => Attendance::create([
            'tenant_id' => $acme->id, 'user_id' => $emp->id, 'branch_id' => $this->makeBranch($acme)->id, 'work_date' => $date,
            'status' => 'late', 'late_minutes' => 20, 'justification_status' => 'pending', 'notes' => 'Tráfico en la vía',
        ]);

        // Rechazo: antes reemplazaba las notas por el motivo (o las dejaba vacías)
        $a = $make('2026-10-07');
        $this->actingIn($hr, $acme)->postJson("/api/admin/attendance/{$a->id}/reject-justification", ['reason' => 'Sin sustento'])->assertOk();
        $a->refresh();
        $this->assertSame('rejected', $a->justification_status);
        $this->assertSame('late', $a->status);
        $this->assertStringContainsString('Tráfico en la vía', $a->notes);
        $this->assertStringContainsString('Sin sustento', $a->notes);
        $this->assertSame($hr->id, $a->reviewed_by);

        // Ya revisada: no se vuelve a decidir encima
        $this->actingIn($hr, $acme)->postJson("/api/admin/attendance/{$a->id}/approve-justification")->assertStatus(422);
        $this->assertSame('rejected', $a->fresh()->justification_status);

        // Aprobación: queda justificada y conserva el texto
        $b = $make('2026-10-08');
        $this->actingIn($hr, $acme)->postJson("/api/admin/attendance/{$b->id}/approve-justification")->assertOk();
        $this->assertSame(['approved', 'excused'], [$b->fresh()->justification_status, $b->fresh()->status]);
        $this->assertStringContainsString('Tráfico en la vía', $b->fresh()->notes);

        // El colaborador no puede revisar
        $c = $make('2026-10-09');
        $this->actingIn($emp, $acme)->postJson("/api/admin/attendance/{$c->id}/approve-justification")->assertForbidden();
        $this->assertSame('pending', $c->fresh()->justification_status);
    }
}
