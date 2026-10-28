<?php

namespace Tests\Feature;

use App\Modules\Core\Services\TenantContext;
use App\Modules\Finance\Models\CompanyBankAccount;
use App\Modules\Finance\Models\FixedCost;
use App\Modules\Finance\Models\PaymentCancellation;
use App\Modules\Finance\Models\PaymentInstallment;
use App\Modules\Finance\Models\PettyCashExpense;
use App\Modules\Finance\Models\Requisition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Costos fijos (cuotas por lote) y flujo de caja real vs proyectado */
class FixedCostCashFlowTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_fixed_cost_generates_monthly_installments_and_each_one_is_paid_or_cancelled(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $accountant = $this->makeUser($acme, 'accountant');
        $account = CompanyBankAccount::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'bank' => 'BCP', 'account_number' => '191', 'currency' => 'PEN']);

        $this->actingAs($accountant)->post('/fixed-costs', ['classification' => 'ARRENDAMIENTO', 'detail' => 'Alquiler', 'date' => '2026-01-31', 'amount' => 1500, 'currency' => 'PEN', 'installments' => 3])
            ->assertSessionHasErrors('supplier_name');
        $this->actingAs($accountant)->post('/fixed-costs', [
            'classification' => 'ARRENDAMIENTO', 'supplier_name' => 'Inmobiliaria Norte', 'detail' => 'Alquiler oficina', 'date' => '2026-01-31',
            'amount' => 1500, 'currency' => 'PEN', 'installments' => 3,
        ])->assertSessionHasNoErrors();

        $costs = FixedCost::withoutGlobalScopes()->orderBy('installment')->get();
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], $costs->map(fn ($c) => $c->date->toDateString())->all(), 'fin de mes sin desbordar');
        $this->assertSame([1], $costs->pluck('batch')->unique()->values()->all());

        $this->actingAs($accountant)->get('/fixed-costs?month=2026-02')
            ->assertInertia(fn ($p) => $p->component('Finance/Pages/FixedCosts/Index')->has('costs', 1)->where('costs.0.of', 3)->where('totals.pending.PEN', 1500));

        [$first, $second] = [$costs[0], $costs[1]];
        $this->actingAs($accountant)->put("/fixed-costs/{$first->id}", ['amount' => 1550, 'date' => '2026-01-31', 'detail' => 'Alquiler + mora'])->assertSessionHasNoErrors();

        // Como en sisgere, la cuota se paga por la cola de Tesorería (antes se marcaba pagada aquí, sin
        // autorización ni voucher): se envía como PG con su orden de pago, se programa, la autoriza la
        // persona designada y otra la paga; al pagarse, la cuota queda pagada sola.
        \Illuminate\Support\Facades\Storage::fake('private');
        \Illuminate\Support\Facades\Notification::fake();
        $boss = $this->makeUser($acme, 'manager');
        \App\Modules\Finance\Models\Signatory::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'user_id' => $boss->id, 'position' => 'Gerente', 'can_authorize' => true]);
        $landlord = \App\Modules\Finance\Models\Supplier::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'name' => 'Inmobiliaria Norte SAC', 'ruc' => '20444444444']);

        $send = ['supplier_id' => $landlord->id, 'due_date' => '2026-02-05', 'payment_method' => 'TRANSFERENCIA', 'file' => $this->fakePdf('recibo.pdf')];
        $this->actingAs($accountant)->post("/fixed-costs/{$first->id}/send", ['supplier_id' => ''] + $send)->assertSessionHasErrors('supplier_id');
        $this->post("/fixed-costs/{$first->id}/send", $send)->assertSessionHasNoErrors();

        $pg = Requisition::withoutGlobalScopes()->where('fixed_cost_id', $first->id)->sole();
        $this->assertTrue($pg->is_direct_payment);
        $this->assertSame(['1550.00', 'Pago a proveedores de bienes y servicios', $landlord->id], [$pg->amount, $pg->cash_flow_concept, $pg->supplier_id]);
        $this->assertStringStartsWith('ORDP OP-', $pg->invoice_number);

        // Una cuota, un pago: ni se reenvía ni se edita o anula mientras está en Tesorería
        $this->post("/fixed-costs/{$first->id}/send", $send)->assertStatus(422);
        $this->put("/fixed-costs/{$first->id}", ['amount' => 1, 'date' => '2026-01-31', 'detail' => 'x'])->assertStatus(422);
        $this->post("/fixed-costs/{$first->id}/cancel")->assertStatus(422);
        $this->assertNull($first->fresh()->paid_at, 'enviar no es pagar');

        $p = $pg->paymentInstallments()->sole();
        $this->post("/treasury/installments/{$p->id}/schedule", ['scheduled_for' => '2026-02-05'])->assertSessionHasNoErrors();
        $this->actingAs($boss)->post("/treasury/installments/{$p->id}/authorize")->assertSessionHasNoErrors();
        $this->actingAs($accountant)->post("/treasury/installments/{$p->id}/pay", ['paid_at' => '2026-02-01', 'amount' => 1550,
            'company_bank_account_id' => $account->id, 'operation_number' => 'OP-9', 'voucher' => $this->fakePdf('v.pdf')])->assertSessionHasNoErrors();

        $first->refresh();
        $this->assertSame(['2026-02-01', 'OP-9', $account->id], [$first->paid_at->toDateString(), $first->operation_number, $first->company_bank_account_id]);
        // Ahora sí aparece en "Cancelaciones por banco" (lo pagado por cuenta)
        $this->assertSame(1, PaymentCancellation::withoutGlobalScopes()->where('payment_installment_id', $p->id)->count());

        // Anular desde la cuota 2 anula 2 y 3; la pagada queda intacta
        $this->actingAs($accountant)->post("/fixed-costs/{$second->id}/cancel", ['rest' => true])->assertSessionHasNoErrors();
        $this->assertSame([true, false, false], FixedCost::withoutGlobalScopes()->orderBy('installment')->pluck('is_active')->all());
        $this->assertSame('1550.00', $first->fresh()->amount);

        $this->actingAs($this->makeUser($acme, 'logistics'))->get('/fixed-costs')->assertForbidden();
    }

    public function test_default_cash_flow_concepts_exist_in_the_catalog(): void
    {
        $catalog = Requisition::cashFlowConcepts();
        $defaults = config('finance.cash_flow_defaults');
        foreach ([$defaults['advance'], $defaults['payroll'], ...array_values($defaults['fixed_cost'])] as $concept) {
            $this->assertContains($concept, $catalog, "«{$concept}» no está en finance.cash_flow_concepts");
        }
    }

    public function test_cash_flow_splits_actual_and_projected_by_month_and_currency(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $t = $acme->id;

        $r = Requisition::withoutGlobalScopes()->create(['tenant_id' => $t, 'series' => 'RQ', 'number' => 1, 'requested_at' => now(), 'detail' => 'x', 'stage' => 'ordered', 'currency' => 'PEN', 'classification' => 'REPUESTOS', 'amount' => 1000, 'installments' => 2]);
        $paid = PaymentInstallment::withoutGlobalScopes()->create(['tenant_id' => $t, 'requisition_id' => $r->id, 'installment' => 1, 'due_date' => '2026-03-10', 'amount' => 500, 'paid_amount' => 200, 'status' => 'pending']);
        PaymentInstallment::withoutGlobalScopes()->create(['tenant_id' => $t, 'requisition_id' => $r->id, 'installment' => 2, 'due_date' => '2026-04-10', 'amount' => 500, 'status' => 'pending']);
        PaymentCancellation::withoutGlobalScopes()->create(['tenant_id' => $t, 'payment_installment_id' => $paid->id, 'paid_at' => '2026-03-05', 'amount' => 200]);
        $usd = Requisition::withoutGlobalScopes()->create(['tenant_id' => $t, 'series' => 'RQ', 'number' => 2, 'requested_at' => now(), 'detail' => 'y', 'stage' => 'ordered', 'currency' => 'USD', 'amount' => 99]);
        PaymentInstallment::withoutGlobalScopes()->create(['tenant_id' => $t, 'requisition_id' => $usd->id, 'installment' => 1, 'due_date' => '2026-03-10', 'amount' => 99, 'status' => 'pending']);
        FixedCost::withoutGlobalScopes()->create(['tenant_id' => $t, 'classification' => 'LEASING', 'date' => '2026-03-15', 'amount' => 800, 'currency' => 'PEN', 'batch' => 1, 'installment' => 1, 'is_active' => true]);
        FixedCost::withoutGlobalScopes()->create(['tenant_id' => $t, 'classification' => 'LEASING', 'date' => '2026-04-15', 'amount' => 800, 'currency' => 'PEN', 'batch' => 1, 'installment' => 2, 'is_active' => false]);
        PettyCashExpense::withoutGlobalScopes()->create(['tenant_id' => $t, 'receipt_number' => 1, 'date' => '2026-03-02', 'description' => 'z', 'amount' => 45, 'cash_box' => 'CAJA EFECTIVO']);
        FixedCost::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'classification' => 'LEASING', 'date' => '2026-03-15', 'amount' => 7777, 'currency' => 'PEN', 'batch' => 1, 'installment' => 1, 'is_active' => true]);

        $manager = $this->makeUser($acme, 'manager');
        $this->actingAs($manager)->get('/cash-flow?from=2026-02')->assertOk()->assertInertia(fn ($p) => $p
            ->component('Finance/Pages/CashFlow/Index')
            ->where('actual', fn ($rows) => collect($rows)->mapWithKeys(fn ($r) => [$r['concept'] => $r['months']['2026-03']])->all() == [
                'Caja chica · CAJA EFECTIVO' => 45, 'Proveedores · REPUESTOS' => 200,
            ])
            ->where('projected', fn ($rows) => collect($rows)->mapWithKeys(fn ($r) => [$r['concept'] => [$r['months']['2026-03'], $r['months']['2026-04']]])->all() == [
                'Costos fijos · LEASING' => [800, 0], 'Proveedores · REPUESTOS' => [300, 500],
            ]));

        // En dólares solo aparece la cuota USD, sin caja chica
        $this->actingAs($manager)->get('/cash-flow?from=2026-02&currency=USD')->assertInertia(fn ($p) => $p
            ->where('actual', [])->where('projected.0.months.2026-03', 99));

        app(TenantContext::class)->clear();
        $this->actingAs($this->makeUser($acme, 'employee'))->get('/cash-flow')->assertRedirect(route('portal.home')); // sin panel: a su portal
    }
}
