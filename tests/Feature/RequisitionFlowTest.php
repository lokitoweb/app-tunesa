<?php

namespace Tests\Feature;

use App\Modules\Core\Services\TenantContext;
use App\Modules\Finance\Models\PaymentInstallment;
use App\Modules\Finance\Models\Product;
use App\Modules\Finance\Models\Requisition;
use App\Modules\Finance\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Requerimiento -> aprobación -> cotización -> OC con cuotas, por rol */
class RequisitionFlowTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'requested_at' => '2026-10-23', 'detail' => 'Llantas para bus 12', 'requested_by' => 'Ana Ruiz', 'area' => 'Flota',
            'classification' => 'REPUESTOS', 'priority' => 'ALTO', 'plate' => 'ABC-123',
            'items' => [
                ['product' => 'Llanta 295/80 R22.5', 'unit' => 'UNIDAD (BIENES)', 'quantity' => 4, 'justification' => 'Desgaste'],
                ['product' => 'Válvula', 'unit' => 'UNIDAD (BIENES)', 'quantity' => 4],
            ],
        ], $overrides);
    }

    public function test_agency_sets_the_series_and_the_requester_is_notified_like_sisgere(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $this->makeBranch($acme, ['name' => 'AGENCIA CAJABAMBA', 'document_series' => '007', 'address' => 'AV. LEONCIO MARTINEZ NRO 405']);
        $requester = $this->makeUser($acme, 'supervisor');

        // FrmReq: "Error seleccione el area que solicita.."
        $this->actingAs($requester)->post('/requisitions', $this->payload(['area' => '']))->assertSessionHasErrors('area');

        $this->actingAs($requester)->get('/requisitions/create')
            ->assertInertia(fn ($page) => $page->where('branches.0.address', 'AV. LEONCIO MARTINEZ NRO 405'));

        $this->actingAs($requester)->post('/requisitions', $this->payload(['agency' => 'AGENCIA CAJABAMBA']))->assertSessionHasNoErrors();
        $r = Requisition::withoutGlobalScopes()->sole();
        $this->assertSame('007', $r->series, 'la serie es la de la agencia elegida (seriereq)');
        $this->assertSame('requisition.requested', $requester->notifications()->sole()->data['type']);
    }

    public function test_a_rejected_requisition_can_be_approved_afterwards_like_sisgere(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $requester = $this->makeUser($acme, 'supervisor');
        $manager = $this->makeUser($acme, 'manager');
        $this->actingAs($requester)->post('/requisitions', $this->payload())->assertSessionHasNoErrors();
        $r = Requisition::withoutGlobalScopes()->sole();

        $this->actingAs($manager)->post("/requisitions/{$r->id}/review", ['decision' => 'rejected', 'notes' => 'Sin stock'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post("/requisitions/{$r->id}/review", ['decision' => 'rejected', 'notes' => 'Otra vez'])->assertStatus(422);
        $this->actingAs($manager)->post("/requisitions/{$r->id}/review", ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $r->fresh()->stage);
        $this->actingAs($manager)->post("/requisitions/{$r->id}/review", ['decision' => 'approved'])->assertStatus(422);
    }

    public function test_full_flow_from_request_to_purchase_order_with_installments(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $requester = $this->makeUser($acme, 'supervisor');
        $manager = $this->makeUser($acme, 'manager');
        $logistics = $this->makeUser($acme, 'logistics');
        $supplier = Supplier::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'name' => 'Llantas Sur', 'ruc' => '20111111111']);

        $this->actingAs($requester)->post('/requisitions', $this->payload())->assertSessionHasNoErrors();
        $this->actingAs($requester)->post('/requisitions', $this->payload(['detail' => 'Otro']))->assertSessionHasNoErrors();
        $r = Requisition::withoutGlobalScopes()->orderBy('id')->first();
        $y = now()->year;
        $this->assertSame(['001', 1, 'requested', "RQ-AC-001-000001-{$y}"], [$r->series, $r->number, $r->stage, $r->code()]);
        $this->assertSame(2, Requisition::withoutGlobalScopes()->max('number'));
        $this->assertSame(2, $r->items()->count());
        $this->assertSame(2, Product::withoutGlobalScopes()->count(), 'los productos no se duplican');

        // Quien solicita no aprueba ni cotiza
        $this->actingAs($requester)->post("/requisitions/{$r->id}/review", ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($requester)->post("/requisitions/{$r->id}/quotations", [])->assertForbidden();

        // Rechazar exige motivo; aprobar pasa a aprobado
        $this->actingAs($manager)->post("/requisitions/{$r->id}/review", ['decision' => 'rejected'])->assertSessionHasErrors('notes');
        $this->actingAs($manager)->post("/requisitions/{$r->id}/review", ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $r->fresh()->stage);
        $this->assertSame($manager->id, $r->fresh()->reviewed_by_id);

        // Ya revisado: no se edita
        $this->actingAs($requester)->put("/requisitions/{$r->id}", $this->payload())->assertStatus(422);

        // Cuenta del proveedor para el pago (sisgere: banco y nro_cuenta de la cotización); la de otro proveedor no vale
        $bcp = $supplier->bankAccounts()->create(['tenant_id' => $acme->id, 'bank' => 'BCP', 'account_number' => '570-111']);
        $supplier->bankAccounts()->create(['tenant_id' => $acme->id, 'bank' => 'BBVA', 'account_number' => '0011-222']);
        $other = Supplier::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'name' => 'Otro', 'ruc' => '20222222222']);
        $foreign = $other->bankAccounts()->create(['tenant_id' => $acme->id, 'bank' => 'IBK', 'account_number' => '200-333']);
        $quote = ['supplier_id' => $supplier->id, 'amount' => 1000, 'currency' => 'PEN', 'quoted_at' => '2026-10-23'];
        $this->actingAs($logistics)->post("/requisitions/{$r->id}/quotations", $quote + ['supplier_bank_account_id' => $foreign->id, 'file' => $this->fakePdf('cot.pdf')])
            ->assertSessionHasErrors('supplier_bank_account_id');
        $this->actingAs($logistics)->post("/requisitions/{$r->id}/quotations", $quote + ['supplier_bank_account_id' => $bcp->id, 'file' => $this->fakePdf('cot.pdf')])
            ->assertSessionHasNoErrors();
        $quotation = $r->quotations()->sole();
        $this->assertSame(['BCP', '570-111'], [$quotation->bank, $quotation->account_number]);
        $this->assertSame('quoting', $r->fresh()->stage);

        $this->actingAs($logistics)->post("/requisitions/{$r->id}/order", [
            'quotation_id' => $quotation->id, 'type' => 'purchase', 'payment_terms' => 'CREDITO', 'payment_method' => 'TRANSFERENCIA',
            'payment_origin' => 'TRANSFERENCIA', 'priority' => 'ALTO', 'installments' => 3, 'first_due_date' => '2026-11-01', 'interval_days' => 30,
        ])->assertSessionHasNoErrors();

        $r->refresh();
        $this->assertSame(['ordered', "OC-AC-001-000001-{$y}", $supplier->id, '1000.00'], [$r->stage, $r->purchase_order_number, $r->supplier_id, $r->amount]);
        $this->assertTrue($quotation->fresh()->is_selected);
        $this->assertSame(['BCP 570-111'], $r->supplierAccounts()->all(), 'Tesorería paga a la cuenta de la cotización');
        $installments = PaymentInstallment::withoutGlobalScopes()->orderBy('installment')->get();
        $this->assertSame(['333.33', '333.33', '333.34'], $installments->pluck('amount')->all());
        $this->assertSame([null, null, null], $installments->pluck('scheduled_for')->all(), 'a crédito quedan por programar (sisgere)');
        // Cada 30 días = mensual por calendario (sisgere DateAdd Month), no se corre al 31/12
        $this->assertSame(['2026-11-01', '2026-12-01', '2027-01-01'], $installments->map(fn ($i) => $i->due_date->toDateString())->all());

        // Con orden emitida ya no se anula ni se vuelve a emitir
        $this->actingAs($logistics)->post("/requisitions/{$r->id}/cancel")->assertStatus(422);
        $this->actingAs($logistics)->post("/requisitions/{$r->id}/order", ['quotation_id' => $quotation->id])->assertStatus(422);

        $this->actingAs($manager)->get("/requisitions/{$r->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('Finance/Pages/Requisitions/Show')->where('requisition.purchase_order_number', "OC-AC-001-000001-{$y}")->has('requisition.installments_list', 3));
    }

    public function test_form_lists_attachment_and_approvers_follow_sisgere_and_never_mix_companies(): void
    {
        \Illuminate\Support\Facades\Storage::fake('private');
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $this->makeBranch($acme, ['name' => 'AGENCIA CAJABAMBA', 'document_series' => '007', 'address' => 'AV. LEONCIO MARTINEZ NRO 405']);
        $requester = $this->makeUser($acme, 'supervisor', ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'email' => 'ana@acme.test']);
        $manager = $this->makeUser($acme, 'manager');
        $outsider = $this->makeUser($other, 'supervisor', ['first_name' => 'Ajeno', 'email' => 'ajeno@other.test']);
        \App\Modules\Finance\Models\Signatory::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'user_id' => $manager->id, 'position' => 'Gerente', 'is_active' => true]);
        \App\Modules\Finance\Models\Signatory::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'user_id' => $outsider->id, 'position' => 'Gerente', 'is_active' => true]);
        $y = now()->year;

        // Listas del formulario (sisgere: Colaborador con su correo, Área, N.º con la serie de la agencia), solo de acme
        $this->actingAs($requester)->get('/requisitions/create')->assertInertia(fn ($p) => $p
            ->where('branches.0.next_code', "RQ-AC-007-000001-{$y}")
            ->where('collaborators', fn ($c) => collect($c)->pluck('email')->contains('ana@acme.test') && ! collect($c)->pluck('email')->contains('ajeno@other.test'))
            ->where('areas', fn ($a) => collect($a)->contains('LOG-LOGISTICA')));
        // La vista previa no reserva el número
        $this->actingAs($requester)->get('/requisitions/create');
        $this->post('/requisitions', $this->payload(['agency' => 'AGENCIA CAJABAMBA']))->assertSessionHasNoErrors();
        $r = Requisition::withoutGlobalScopes()->sole();
        $this->assertSame("RQ-AC-007-000001-{$y}", $r->code());
        // Buscar: Desde / Hasta por fecha del requerimiento (2026-10-23 en el payload)
        $this->get('/requisitions?from=2026-10-24')->assertInertia(fn ($p) => $p->where('requisitions.total', 0));
        $this->get('/requisitions?from=2026-10-23&to=2026-10-23')->assertInertia(fn ($p) => $p->where('requisitions.total', 1));

        // Req Aprobado por: los apoderados de la empresa, no los de otra
        $this->get("/requisitions/{$r->id}")->assertInertia(fn ($p) => $p->where('requisition.approvers', [$manager->full_name . ' · Gerente'])->where('requisition.has_attachment', false));

        // Adjuntar comprobante: imagen o PDF; texto no; otra empresa no lo ve
        $this->post("/requisitions/{$r->id}/attachment", ['file' => \Illuminate\Http\UploadedFile::fake()->create('nota.txt', 1, 'text/plain')])->assertSessionHasErrors('file');
        $this->post("/requisitions/{$r->id}/attachment", ['file' => $this->fakePdf('sustento.pdf')])->assertSessionHasNoErrors();
        $this->assertTrue((bool) $r->fresh()->attachments['request']);
        $this->get("/requisitions/{$r->id}/attachment")->assertOk();
        $this->actingAs($outsider)->get("/requisitions/{$r->id}/attachment")->assertNotFound();
        $this->actingAs($outsider)->post("/requisitions/{$r->id}/attachment", ['file' => $this->fakePdf()])->assertNotFound();
    }

    public function test_visibility_and_tenant_isolation(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $mine = $this->makeUser($acme, 'supervisor');
        $colleague = $this->makeUser($acme, 'supervisor');

        $this->actingAs($mine)->post('/requisitions', $this->payload())->assertSessionHasNoErrors();
        $r = Requisition::withoutGlobalScopes()->sole();

        // Sin view_all solo se ven los propios
        $this->actingAs($colleague)->get('/requisitions')->assertInertia(fn ($p) => $p->where('requisitions.total', 0));
        $this->actingAs($colleague)->get("/requisitions/{$r->id}")->assertNotFound();
        $this->actingAs($mine)->get('/requisitions')->assertInertia(fn ($p) => $p->where('requisitions.total', 1));

        // Otra empresa no lo ve ni lo toca
        app(TenantContext::class)->clear();
        $alien = $this->makeUser($other, 'company_admin');
        $this->actingAs($alien)->get("/requisitions/{$r->id}")->assertNotFound();
        $this->actingAs($alien)->post("/requisitions/{$r->id}/review", ['decision' => 'approved'])->assertNotFound();
        $this->assertSame('requested', $r->fresh()->stage);

        // Validación: sin productos no se registra
        app(TenantContext::class)->clear();
        $this->actingAs($mine)->post('/requisitions', $this->payload(['items' => []]))->assertSessionHasErrors('items');
    }

    public function test_express_is_validated_by_the_immediate_boss_and_ordered_with_one_supplier(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $requester = $this->makeUser($acme, 'supervisor');
        $boss = $this->makeUser($acme, 'supervisor');
        $otherBoss = $this->makeUser($acme, 'supervisor');
        $branch = $this->makeBranch($acme, ['supervisor_id' => $boss->id]);
        $requester->branches()->attach($branch->id, ['is_primary' => true]);
        $logistics = $this->makeUser($acme, 'logistics');
        $supplier = Supplier::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'name' => 'Ferretería', 'ruc' => '20222222222']);

        // Tope: menor a S/ 600 y con monto
        $this->actingAs($requester)->post('/requisitions', $this->payload(['is_express' => true]))->assertSessionHasErrors('amount');
        $this->actingAs($requester)->post('/requisitions', $this->payload(['is_express' => true, 'amount' => 600]))->assertSessionHasErrors('amount');
        $this->actingAs($requester)->post('/requisitions', $this->payload(['is_express' => true, 'amount' => 450.5]))->assertSessionHasNoErrors();
        $r = Requisition::withoutGlobalScopes()->sole();
        $this->assertTrue($r->is_express);
        $this->assertSame(['450.50', 'PEN'], [$r->amount, $r->currency]);

        // Lo valida su jefatura inmediata (no otro supervisor ni quien lo pidió)
        $this->actingAs($requester)->post("/requisitions/{$r->id}/review", ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($otherBoss)->post("/requisitions/{$r->id}/review", ['decision' => 'approved'])->assertNotFound();
        $this->actingAs($boss)->get('/requisitions')->assertInertia(fn ($p) => $p->has('requisitions.data', 1));
        $this->actingAs($boss)->post("/requisitions/{$r->id}/review", ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $r->fresh()->stage);

        // Un solo proveedor, en soles y bajo el tope; sin cuadro comparativo
        $quote = fn (array $o = []) => $this->actingAs($logistics)->post("/requisitions/{$r->id}/quotations", $o + [
            'supplier_id' => $supplier->id, 'amount' => 480, 'currency' => 'PEN', 'quoted_at' => '2026-10-23',
        ]);
        $quote(['amount' => 650])->assertSessionHasErrors('amount');
        $quote(['currency' => 'USD'])->assertSessionHasErrors('amount');
        $quote()->assertSessionHasNoErrors();
        $quote()->assertStatus(422);

        $this->actingAs($logistics)->post("/requisitions/{$r->id}/order", [
            'quotation_id' => $r->quotations()->sole()->id, 'type' => 'purchase', 'payment_terms' => 'CONTADO', 'payment_method' => 'EFECTIVO',
            'payment_origin' => 'CAJA EFECTIVO', 'priority' => 'NORMAL', 'installments' => 1, 'first_due_date' => '2026-10-24', 'interval_days' => 30,
        ])->assertSessionHasNoErrors();
        $this->assertSame('ordered', $r->fresh()->stage);
        // sisgere FrmReq: al CONTADO la cuota queda programada para hoy por quien emite la orden
        $this->assertSame(now(tenant_timezone())->toDateString(), $r->paymentInstallments()->sole()->scheduled_for?->toDateString());

        // Un requerimiento normal sigue necesitando gerencia o contabilidad
        $this->actingAs($requester)->post('/requisitions', $this->payload())->assertSessionHasNoErrors();
        $normal = Requisition::withoutGlobalScopes()->where('is_express', false)->sole();
        $this->actingAs($boss)->post("/requisitions/{$normal->id}/review", ['decision' => 'approved'])->assertNotFound();
        $this->actingAs($this->makeUser($acme, 'accountant'))->post("/requisitions/{$normal->id}/review", ['decision' => 'approved'])->assertSessionHasNoErrors();
    }
}
