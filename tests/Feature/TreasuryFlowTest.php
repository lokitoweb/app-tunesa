<?php

namespace Tests\Feature;

use App\Modules\Core\Services\TenantContext;
use App\Modules\Finance\Models\CompanyBankAccount;
use App\Modules\Finance\Models\PaymentCancellation;
use App\Modules\Finance\Models\PaymentInstallment;
use App\Modules\Finance\Models\PettyCashExpense;
use App\Modules\Finance\Models\Requisition;
use App\Modules\Finance\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Tesorería: programar -> autorizar -> pagar (parcial y total) con voucher; caja chica */
class TreasuryFlowTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function orderedRequisition(int $tenantId, float $amount = 1000, int $installments = 1, bool $detraction = false): Requisition
    {
        $supplier = Supplier::withoutGlobalScopes()->create(['tenant_id' => $tenantId, 'name' => 'Proveedor SAC', 'ruc' => '2010000000' . $tenantId]);
        $r = Requisition::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'series' => 'RQ', 'number' => 1, 'requested_at' => now(), 'detail' => 'Llantas', 'stage' => 'ordered',
            'supplier_id' => $supplier->id, 'amount' => $amount, 'currency' => 'PEN', 'installments' => $installments,
            'purchase_order_number' => 'OC-000001', 'has_detraction' => $detraction,
        ]);
        for ($i = 1; $i <= $installments; $i++) {
            PaymentInstallment::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'requisition_id' => $r->id, 'installment' => $i, 'due_date' => now()->addDays(30 * $i)->toDateString(),
                'amount' => round($amount / $installments, 2), 'status' => 'pending',
            ]);
        }

        return $r;
    }

    public function test_schedule_authorize_and_pay_in_two_parts_with_voucher(): void
    {
        Storage::fake('private');
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $accountant = $this->makeUser($acme, 'accountant');
        $manager = $this->makeUser($acme, 'manager');
        $r = $this->orderedRequisition($acme->id, 1000);
        $p = PaymentInstallment::withoutGlobalScopes()->sole();
        $account = CompanyBankAccount::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'bank' => 'BCP', 'account_number' => '191-000', 'currency' => 'PEN']);

        $this->actingAs($accountant)->get('/treasury')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Finance/Pages/Treasury/Index')->where('counts.to_schedule', 1)->where('kpis.pending', 1000));

        // Sin programar no se autoriza; sin autorizar no se paga
        $this->actingAs($manager)->post("/treasury/installments/{$p->id}/authorize")->assertStatus(422);
        $this->actingAs($accountant)->post("/treasury/installments/{$p->id}/schedule", ['scheduled_for' => now()->addDays(5)->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($accountant)->post("/treasury/installments/{$p->id}/pay", [])->assertStatus(422);

        // Contabilidad no autoriza: lo hace gerencia
        // Logística no autoriza (sin requisitions.approve)
        $this->actingAs($this->makeUser($acme, 'logistics'))->post("/treasury/installments/{$p->id}/authorize")->assertForbidden();
        $this->actingAs($manager)->post("/treasury/installments/{$p->id}/authorize")->assertSessionHasNoErrors();
        // Gerencia autoriza pero no paga; quien autorizó tampoco registra el pago
        $self = $this->makeUser($acme, 'accountant');
        $q = PaymentInstallment::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'requisition_id' => $r->id, 'installment' => 2, 'amount' => 1, 'status' => 'pending', 'scheduled_for' => now()->toDateString(), 'authorized_by_id' => $self->id, 'authorized_at' => now()]);
        $this->actingAs($self)->post("/treasury/installments/{$q->id}/pay", ['paid_at' => now()->toDateString(), 'amount' => 1, 'company_bank_account_id' => $account->id, 'operation_number' => 'X'])->assertForbidden();
        $q->delete();
        $this->actingAs($manager)->post("/treasury/installments/{$p->id}/pay", [])->assertForbidden();

        $pay = fn (array $over = []) => array_merge([
            'paid_at' => now()->toDateString(), 'amount' => 400, 'company_bank_account_id' => $account->id, 'operation_number' => 'OP-1',
        ], $over);

        // No se paga más que el saldo
        $this->actingAs($accountant)->post("/treasury/installments/{$p->id}/pay", $pay(['amount' => 1000.01]))->assertSessionHasErrors('amount');

        $this->actingAs($accountant)->post("/treasury/installments/{$p->id}/pay", $pay(['voucher' => UploadedFile::fake()->create('v1.pdf', 100, 'application/pdf')]))
            ->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertSame(['pending', '400.00'], [$p->status, $p->paid_amount]);
        $this->assertTrue(PaymentCancellation::withoutGlobalScopes()->first()->is_amortization);

        $this->actingAs($accountant)->post("/treasury/installments/{$p->id}/pay", $pay(['amount' => 600, 'operation_number' => 'OP-2']))->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertSame(['paid', '1000.00'], [$p->status, $p->paid_amount]);
        $this->assertSame('paid', $r->fresh()->stage, 'todas las cuotas pagadas: requerimiento pagado');

        // El voucher se guarda en el disco privado y solo se sirve con permiso
        $first = PaymentCancellation::withoutGlobalScopes()->orderBy('id')->first();
        Storage::disk('private')->assertExists($first->voucher_path);
        $this->actingAs($accountant)->get("/treasury/payments/{$first->id}/voucher")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($this->makeUser($acme, 'logistics'))->get("/treasury/payments/{$first->id}/voucher")->assertForbidden();

        // Cuota pagada ya no acepta pagos
        $this->actingAs($accountant)->post("/treasury/installments/{$p->id}/pay", $pay())->assertStatus(422);
    }

    public function test_detraction_and_tenant_isolation(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $r = $this->orderedRequisition($acme->id, 5000, 2, detraction: true);
        $p = PaymentInstallment::withoutGlobalScopes()->first();
        $accountant = $this->makeUser($acme, 'accountant');

        $this->actingAs($accountant)->post("/treasury/requisitions/{$r->id}/detraction", ['detraction_number' => 'D-1', 'detraction_percent' => 12, 'detraction_amount' => 9000])
            ->assertSessionHasErrors('detraction_amount');
        $this->actingAs($accountant)->post("/treasury/requisitions/{$r->id}/detraction", ['detraction_number' => 'D-1', 'detraction_percent' => 12, 'detraction_amount' => 600])
            ->assertSessionHasNoErrors();
        $this->assertSame('600.00', $r->fresh()->detraction_amount);

        app(TenantContext::class)->clear();
        $alien = $this->makeUser($other, 'company_admin');
        $this->actingAs($alien)->post("/treasury/installments/{$p->id}/schedule", ['scheduled_for' => now()->toDateString()])->assertNotFound();
        $this->actingAs($alien)->get('/treasury')->assertInertia(fn ($page) => $page->where('installments.total', 0));
        $this->assertNull($p->fresh()->scheduled_for);
    }

    public function test_petty_cash_receipts_are_sequential_with_private_voucher(): void
    {
        Storage::fake('private');
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $accountant = $this->makeUser($acme, 'accountant');
        $data = ['date' => now()->toDateString(), 'description' => 'Útiles de oficina', 'document_type' => 'BOLETA', 'amount' => 45.5, 'cash_box' => 'CAJA EFECTIVO'];

        $this->actingAs($accountant)->post('/petty-cash', $data + ['voucher' => UploadedFile::fake()->image('boleta.jpg')])->assertSessionHasNoErrors();
        $this->actingAs($accountant)->post('/petty-cash', array_merge($data, ['amount' => 10]))->assertSessionHasNoErrors();
        $this->actingAs($accountant)->post('/petty-cash', array_merge($data, ['date' => now()->addDay()->toDateString()]))->assertSessionHasErrors('date');
        $this->actingAs($accountant)->post('/petty-cash', $data + ['voucher' => UploadedFile::fake()->create('x.exe', 10)])->assertSessionHasErrors('voucher');

        $this->assertSame([1, 2], PettyCashExpense::withoutGlobalScopes()->orderBy('id')->pluck('receipt_number')->all());
        $first = PettyCashExpense::withoutGlobalScopes()->orderBy('id')->first();
        Storage::disk('private')->assertExists($first->voucher_path);

        $this->actingAs($accountant)->get('/petty-cash')->assertInertia(fn ($page) => $page->component('Finance/Pages/PettyCash/Index')->where('total', 55.5));
        $this->actingAs($this->makeUser($acme, 'logistics'))->get('/petty-cash')->assertForbidden();

        $this->actingAs($accountant)->delete("/petty-cash/{$first->id}")->assertSessionHasNoErrors();
        $this->actingAs($accountant)->post('/petty-cash', $data)->assertSessionHasNoErrors();
        $this->assertSame(3, PettyCashExpense::withoutGlobalScopes()->max('receipt_number'), 'el correlativo no reutiliza recibos eliminados');
    }
}
