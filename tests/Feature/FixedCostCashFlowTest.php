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
        $this->actingAs($accountant)->post("/fixed-costs/{$first->id}/pay", ['paid_at' => '2026-02-01', 'company_bank_account_id' => $account->id, 'operation_number' => 'OP-9'])->assertSessionHasNoErrors();
        $this->actingAs($accountant)->post("/fixed-costs/{$first->id}/pay", ['paid_at' => '2026-02-01', 'company_bank_account_id' => $account->id, 'operation_number' => 'X'])->assertStatus(422);

        // Anular desde la cuota 2 anula 2 y 3; la pagada queda intacta
        $this->actingAs($accountant)->post("/fixed-costs/{$second->id}/cancel", ['rest' => true])->assertSessionHasNoErrors();
        $this->assertSame([true, false, false], FixedCost::withoutGlobalScopes()->orderBy('installment')->pluck('is_active')->all());
        $this->assertSame('1550.00', $first->fresh()->amount);

        $this->actingAs($this->makeUser($acme, 'logistics'))->get('/fixed-costs')->assertForbidden();
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
        $this->actingAs($this->makeUser($acme, 'employee'))->get('/cash-flow')->assertRedirect('/login');
    }
}
