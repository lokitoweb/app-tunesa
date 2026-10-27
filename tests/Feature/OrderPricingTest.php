<?php

namespace Tests\Feature;

use App\Modules\Finance\Models\PaymentInstallment;
use App\Modules\Finance\Models\Requisition;
use App\Modules\Finance\Models\Supplier;
use App\Modules\Finance\Services\OrderPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Brecha 1 con sisgere (FrmReq): precio unitario, descuento e IGV por ítem, y la OC/OS con
 * valor venta, IGV y total. Sin precios por ítem la orden se emite como antes (total de la cotización).
 */
class OrderPricingTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_igv_math_matches_sisgere_buttons(): void
    {
        $lines = [['quantity' => 4, 'unit_price' => 295, 'discount_percent' => 0], ['quantity' => 2, 'unit_price' => 50, 'discount_percent' => 10]];

        // Precios CON IGV (botón "Igv"): 4×295 + 2×50×0.9 = 1270 → valor venta 1270/1.18
        $with = OrderPricing::compute($lines, true, 0.18);
        $this->assertSame([1180.0, 90.0], $with['lines']);
        $this->assertSame([1076.27, 193.73, 1270.0], [$with['taxable'], $with['igv'], $with['total']]);

        // Precios SIN IGV (botón "S/Igv"): el IGV se suma
        $without = OrderPricing::compute($lines, false, 0.18);
        $this->assertSame([1270.0, 228.6, 1498.6], [$without['taxable'], $without['igv'], $without['total']]);

        // El descuento no pasa de 0–100 %
        $this->assertSame(0.0, OrderPricing::lineSubtotal(3, 10, 150));
        $this->assertSame(30.0, OrderPricing::lineSubtotal(3, 10, -5));
        // La tasa sale de la configuración cuando no se indica
        $this->assertSame(0.18, OrderPricing::compute($lines, true)['rate']);
    }

    private function quotedRequisition(): array
    {
        Storage::fake('private');
        Notification::fake();
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $requester = $this->makeUser($acme, 'supervisor');
        $manager = $this->makeUser($acme, 'manager');
        $logistics = $this->makeUser($acme, 'logistics');
        $supplier = Supplier::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'name' => 'Llantas Sur', 'ruc' => '20111111111']);

        $this->actingAs($requester)->post('/requisitions', [
            'requested_at' => '2026-10-23', 'requested_by' => 'Ana', 'detail' => 'Llantas y aceite', 'classification' => 'REPUESTOS', 'priority' => 'ALTO',
            'items' => [['product' => 'Llanta 295', 'unit' => 'UNIDAD (BIENES)', 'quantity' => 4], ['product' => 'Aceite 15W40', 'unit' => 'GALON', 'quantity' => 2]],
        ])->assertSessionHasNoErrors();
        $r = Requisition::withoutGlobalScopes()->sole();
        $this->actingAs($manager)->post("/requisitions/{$r->id}/review", ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->actingAs($logistics)->post("/requisitions/{$r->id}/quotations", ['supplier_id' => $supplier->id, 'amount' => 1200, 'currency' => 'PEN',
            'quoted_at' => '2026-10-23', 'file' => $this->fakePdf('cot.pdf')])->assertSessionHasNoErrors();

        return [$r, $r->quotations()->sole(), $logistics, $manager];
    }

    private function orderPayload(int $quotationId, array $extra = []): array
    {
        return ['quotation_id' => $quotationId, 'type' => 'purchase', 'payment_terms' => 'CREDITO', 'payment_method' => 'TRANSFERENCIA',
            'payment_origin' => 'TRANSFERENCIA', 'priority' => 'ALTO', 'installments' => 2, 'first_due_date' => '2026-11-01', 'interval_days' => 30] + $extra;
    }

    public function test_order_with_item_prices_uses_their_total_with_igv(): void
    {
        [$r, $q, $logistics, $manager] = $this->quotedRequisition();
        [$tire, $oil] = $r->items()->orderBy('id')->get()->all();

        // Si se llena un precio, van todos
        $this->actingAs($logistics)->post("/requisitions/{$r->id}/order", $this->orderPayload($q->id, [
            'prices_include_igv' => true, 'items' => [['id' => $tire->id, 'unit_price' => 295], ['id' => $oil->id, 'unit_price' => '']],
        ]))->assertSessionHasErrors('items');
        // Un ítem de otro requerimiento no se acepta
        $this->post("/requisitions/{$r->id}/order", $this->orderPayload($q->id, ['items' => [['id' => 999999, 'unit_price' => 1]]]))->assertSessionHasErrors('items.0.id');
        $this->assertSame('quoting', $r->fresh()->stage);

        $this->post("/requisitions/{$r->id}/order", $this->orderPayload($q->id, [
            'prices_include_igv' => true,
            'items' => [['id' => $tire->id, 'unit_price' => 295, 'discount_percent' => 0], ['id' => $oil->id, 'unit_price' => 50, 'discount_percent' => 10]],
        ]))->assertSessionHasNoErrors();

        $r->refresh();
        $this->assertSame(['ordered', '1270.00', '1270.00', '1076.27', '193.73', '0.1800', true],
            [$r->stage, $r->amount, $r->amount_payable, $r->taxable_amount, $r->igv_amount, $r->igv_rate, $r->prices_include_igv]);
        $this->assertSame(['295.0000', '1180.00'], [$tire->fresh()->unit_price, $tire->fresh()->subtotal]);
        $this->assertSame(['10.00', '90.00'], [$oil->fresh()->discount_percent, $oil->fresh()->subtotal]);
        // Cuotas sobre el total con precios (no sobre los 1200 de la cotización)
        $this->assertSame(['635.00', '635.00'], PaymentInstallment::withoutGlobalScopes()->orderBy('installment')->pluck('amount')->all());

        // La pantalla muestra el desglose
        $this->actingAs($manager)->get("/requisitions/{$r->id}")->assertInertia(fn ($p) => $p
            ->where('requisition.pricing.taxable', 1076.27)->where('requisition.pricing.igv', 193.73)->where('requisition.pricing.total', 1270)
            ->where('requisition.items.0.subtotal', 1180));
        $this->get("/requisitions/{$r->id}/order.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_order_without_item_prices_works_as_before(): void
    {
        [$r, $q, $logistics] = $this->quotedRequisition();
        $this->actingAs($logistics)->post("/requisitions/{$r->id}/order", $this->orderPayload($q->id))->assertSessionHasNoErrors();

        $r->refresh();
        $this->assertSame(['1200.00', null, null, null], [$r->amount, $r->igv_rate, $r->taxable_amount, $r->igv_amount]);
        $this->assertNull($r->items()->first()->unit_price);
        $this->assertSame(['600.00', '600.00'], PaymentInstallment::withoutGlobalScopes()->orderBy('installment')->pluck('amount')->all());
        $this->get("/requisitions/{$r->id}")->assertInertia(fn ($p) => $p->where('requisition.pricing', null));
    }

    public function test_pdf_template_prints_taxable_value_igv_and_total(): void
    {
        $html = view('finance.document', [
            'company' => 'Turismo Negreiros', 'color' => '#0d6efd', 'title' => 'ORDEN DE COMPRA', 'number' => 'OC-1', 'date' => '26/10/2026',
            'tenant' => null, 'supplier' => null, 'rows' => [], 'currency' => 'PEN', 'amount' => 1270.0, 'signature' => null,
            'items' => [['Llanta 295', 'UND', 4.0, 295.0, 0.0, 1180.0], ['Aceite', 'GAL', 2.0, 50.0, 10.0, 90.0]],
            'breakdown' => ['taxable' => 1076.27, 'igv' => 193.73, 'rate' => 0.18, 'includes_igv' => true],
        ])->render();

        foreach (['P. unitario', 'Importe', 'Valor venta', 'S/ 1,076.27', 'IGV (18 %)', 'S/ 193.73', 'S/ 1,270.00', '10 %', 'con IGV incluido'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }

        // Sin desglose (orden antigua u orden de pago): como antes
        $plain = view('finance.document', ['company' => 'X', 'color' => '#000', 'title' => 'OC', 'number' => '1', 'date' => null, 'tenant' => null,
            'supplier' => null, 'rows' => [], 'currency' => 'PEN', 'amount' => 1200.0, 'signature' => null, 'items' => [['Llanta', 'UND', 4.0]]])->render();
        $this->assertStringContainsString('Total: S/ 1,200.00', $plain);
        $this->assertStringNotContainsString('Valor venta', $plain);
    }
}
