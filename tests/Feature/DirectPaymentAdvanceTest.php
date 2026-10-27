<?php

namespace Tests\Feature;

use App\Modules\Finance\Models\CompanyBankAccount;
use App\Modules\Finance\Models\PaymentInstallment;
use App\Modules\Finance\Models\Requisition;
use App\Modules\Finance\Models\Signatory;
use App\Modules\Finance\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Pago directo de Tesorería (FrmReqProg › Registrar, PG-…) y anticipos que se descuentan de la
 * siguiente OC/OS del mismo proveedor (FrmReq › Aplicar anticipo).
 */
class DirectPaymentAdvanceTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_direct_payment_advance_is_discounted_from_the_next_order(): void
    {
        Storage::fake('private');
        Notification::fake();
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $treasurer = $this->makeUser($acme, 'accountant');
        $boss = $this->makeUser($acme, 'manager');
        $logistics = $this->makeUser($acme, 'logistics');
        $requester = $this->makeUser($acme, 'supervisor');
        Signatory::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'user_id' => $boss->id, 'position' => 'Gerente', 'can_authorize' => true]);
        $supplier = Supplier::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'name' => 'Taller Norte', 'ruc' => '20222222222']);
        $other = Supplier::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'name' => 'Otro', 'ruc' => '20333333333']);
        $account = CompanyBankAccount::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'bank' => 'BCP', 'account_number' => '191-1', 'currency' => 'PEN', 'is_active' => true]);

        $payload = ['supplier_id' => $supplier->id, 'detail' => 'Anticipo 50% reparación', 'document_type' => 'ORDP', 'document_number' => 'a-15',
            'document_date' => now()->toDateString(), 'file' => $this->fakePdf('doc.pdf'), 'currency' => 'PEN', 'amount' => 500, 'due_date' => now()->toDateString(),
            'payment_method' => 'TRANSFERENCIA', 'is_advance' => true];
        $this->actingAs($treasurer)->post('/treasury/direct', ['document_type' => 'XXX'] + $payload)->assertSessionHasErrors('document_type');
        $this->post('/treasury/direct', $payload)->assertSessionHasNoErrors();

        $pg = Requisition::withoutGlobalScopes()->sole();
        $this->assertTrue($pg->is_direct_payment);
        $this->assertStringStartsWith('PG-', $pg->code());
        $this->assertSame([$pg->code(), 'ORDP A-15', 'ordered'], [$pg->orderNumber(), $pg->invoice_number, $pg->stage]);
        $adv = $pg->paymentInstallments()->sole();
        $this->assertTrue($adv->is_advance);

        // Misma cola de Tesorería: programar → autorizar (otra persona) → pagar
        $this->post("/treasury/installments/{$adv->id}/schedule", ['scheduled_for' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($boss)->post("/treasury/installments/{$adv->id}/authorize")->assertSessionHasNoErrors();
        $this->actingAs($treasurer)->post("/treasury/installments/{$adv->id}/pay", ['paid_at' => now()->toDateString(), 'amount' => 500,
            'company_bank_account_id' => $account->id, 'operation_number' => 'OP-1', 'voucher' => $this->fakePdf('v.pdf')])->assertSessionHasNoErrors();
        $this->assertSame('paid', $adv->fresh()->status);
        // Quien pagó no se avisa a sí mismo
        Notification::assertNotSentTo($treasurer, \App\Modules\Finance\Notifications\RequisitionPaid::class);

        // Siguiente orden del mismo proveedor: el anticipo aparece y se descuenta
        $this->actingAs($requester)->post('/requisitions', ['requested_at' => now()->toDateString(), 'requested_by' => 'Ana', 'detail' => 'Reparación motor',
            'classification' => 'REPUESTOS', 'priority' => 'ALTO', 'items' => [['product' => 'Reparación', 'unit' => 'UNIDAD (SERVICIOS)', 'quantity' => 1]]]);
        $r = Requisition::withoutGlobalScopes()->where('is_direct_payment', false)->sole();
        $this->actingAs($boss)->post("/requisitions/{$r->id}/review", ['decision' => 'approved']);
        $this->actingAs($logistics)->post("/requisitions/{$r->id}/quotations", ['supplier_id' => $supplier->id, 'amount' => 1200, 'currency' => 'PEN',
            'quoted_at' => now()->toDateString(), 'file' => $this->fakePdf('c.pdf')])->assertSessionHasNoErrors();
        $q = $r->quotations()->sole();
        $this->get("/requisitions/{$r->id}")->assertInertia(fn ($p) => $p->where("requisition.advances.{$supplier->id}.0.amount", 500));

        $order = ['quotation_id' => $q->id, 'type' => 'service', 'payment_terms' => 'CREDITO', 'payment_method' => 'TRANSFERENCIA', 'payment_origin' => 'TRANSFERENCIA',
            'priority' => 'ALTO', 'installments' => 2, 'first_due_date' => now()->addMonth()->toDateString(), 'interval_days' => 30];
        // Un anticipo de otro proveedor (o inexistente) no se acepta
        $this->post("/requisitions/{$r->id}/order", $order + ['advance_ids' => [999999]])->assertSessionHasErrors('advance_ids');
        $this->post("/requisitions/{$r->id}/order", $order + ['advance_ids' => [$adv->id]])->assertSessionHasNoErrors();

        $r->refresh();
        $this->assertSame(['1200.00', '500.00', '700.00'], [$r->amount, $r->advance_amount, $r->amount_payable]);
        $this->assertSame(['350.00', '350.00'], $r->paymentInstallments()->pluck('amount')->all());

        // FrmNOTIFICADOR: al pagar una cuota, aviso "Se registró un pago" a quien pidió el requerimiento
        $first = $r->paymentInstallments()->first();
        $this->actingAs($treasurer)->post("/treasury/installments/{$first->id}/schedule", ['scheduled_for' => now()->toDateString()]);
        $this->actingAs($boss)->post("/treasury/installments/{$first->id}/authorize");
        $r->update(['invoice_path' => 'x.pdf', 'invoice_number' => 'F001-1']);
        $this->actingAs($treasurer)->post("/treasury/installments/{$first->id}/pay", ['paid_at' => now()->toDateString(), 'amount' => 350,
            'company_bank_account_id' => $account->id, 'operation_number' => 'OP-2', 'voucher' => $this->fakePdf('v2.pdf')])->assertSessionHasNoErrors();
        Notification::assertSentTo($requester, \App\Modules\Finance\Notifications\RequisitionPaid::class,
            fn ($n) => str_contains($n->body(), 'S/ 350.00') && $n->title() === 'Se registró un pago');
        $this->assertSame($r->id, $adv->fresh()->advance_applied_to_id);
        // Ya aplicado: no vuelve a ofrecerse
        $this->assertSame(0, PaymentInstallment::where('is_advance', true)->whereNull('advance_applied_to_id')->count());
        $this->assertNotNull($other);

        // Autorizar varias marcadas: todo o nada, y solo la persona designada
        $second = $r->paymentInstallments()->where('installment', 2)->first();
        $this->actingAs($boss)->post('/treasury/installments/authorize', ['ids' => [$second->id]])->assertStatus(422); // sin programar
        $this->actingAs($treasurer)->post("/treasury/installments/{$second->id}/schedule", ['scheduled_for' => now()->toDateString()]);
        $this->actingAs($logistics)->post('/treasury/installments/authorize', ['ids' => [$second->id]])->assertForbidden();
        $this->actingAs($boss)->post('/treasury/installments/authorize', ['ids' => [$second->id]])->assertSessionHasNoErrors();
        $this->assertNotNull($second->fresh()->authorized_at);

        // Corregir un pago registrado: cuenta, operación y voucher (el monto no)
        $cancel = \App\Modules\Finance\Models\PaymentCancellation::withoutGlobalScopes()->where('payment_installment_id', $first->id)->sole();
        $oldVoucher = $cancel->voucher_path;
        $other2 = CompanyBankAccount::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'bank' => 'BBVA', 'account_number' => '0011-2', 'currency' => 'PEN', 'is_active' => true]);
        $this->actingAs($logistics)->post("/treasury/payments/{$cancel->id}/correct", ['company_bank_account_id' => $other2->id, 'operation_number' => 'X'])->assertForbidden();
        $this->actingAs($treasurer)->post("/treasury/payments/{$cancel->id}/correct", ['company_bank_account_id' => $other2->id, 'operation_number' => 'OP-2B',
            'voucher' => $this->fakePdf('nuevo.pdf'), 'amount' => 1])->assertSessionHasNoErrors();
        $cancel->refresh();
        $this->assertSame(['BBVA', 'OP-2B', '350.00'], [$cancel->bank, $cancel->operation_number, $cancel->amount]);
        Storage::disk('private')->assertMissing($oldVoucher);
        Storage::disk('private')->assertExists($cancel->voucher_path);

        // Orden de pago sin número: correlativo OP-… propio (requerimiento_mostrar_nroODP); una factura sí exige número
        $this->actingAs($treasurer)->post('/treasury/direct', ['document_type' => 'FACT', 'document_number' => ''] + $payload)->assertSessionHasErrors('document_number');
        foreach ([1, 2] as $n) {
            $this->post('/treasury/direct', ['document_number' => '', 'is_advance' => false, 'file' => $this->fakePdf("d{$n}.pdf")] + $payload)->assertSessionHasNoErrors();
        }
        $this->assertSame(["ORDP OP-{$acme->code}-001-000001-" . now()->year, "ORDP OP-{$acme->code}-001-000002-" . now()->year],
            Requisition::withoutGlobalScopes()->where('is_direct_payment', true)->where('invoice_number', 'like', 'ORDP OP-%')->orderBy('id')->pluck('invoice_number')->all());

        // Corregir un pago directo sin pagos: cambia el monto, rehace la cuota y pide autorizar otra vez
        $pg2 = Requisition::withoutGlobalScopes()->where('invoice_number', 'like', 'ORDP OP-%')->orderBy('id')->first();
        $inst = $pg2->paymentInstallments()->sole();
        $inst->update(['scheduled_for' => now()->toDateString(), 'authorized_by_id' => $boss->id, 'authorized_at' => now()]);
        $this->put("/treasury/direct/{$pg2->id}", ['detail' => 'Corregido', 'currency' => 'PEN', 'amount' => 650])->assertSessionHasNoErrors();
        $this->assertSame(['Corregido', '650.00', '650.00', null], [$pg2->fresh()->detail, $pg2->fresh()->amount, $inst->fresh()->amount, $inst->fresh()->authorized_at]);
        // Ya pagado (el anticipo del inicio), no se corrige
        $this->put("/treasury/direct/{$pg->id}", ['detail' => 'x', 'currency' => 'PEN', 'amount' => 1])->assertStatus(422);
    }
}
