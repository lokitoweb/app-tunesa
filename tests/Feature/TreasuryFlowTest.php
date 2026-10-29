<?php

namespace Tests\Feature;

use App\Modules\Core\Services\TenantContext;
use App\Modules\Finance\Models\CompanyBankAccount;
use App\Modules\Finance\Models\PaymentCancellation;
use App\Modules\Finance\Models\PaymentInstallment;
use App\Modules\Finance\Models\PettyCashExpense;
use App\Modules\Finance\Models\Requisition;
use App\Modules\Finance\Models\Signatory;
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
        $supplier = Supplier::withoutGlobalScopes()->create(['tenant_id' => $tenantId, 'name' => 'Proveedor SAC', 'ruc' => sprintf('20%09d', $tenantId)]);
        $r = Requisition::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'series' => 'RQ', 'number' => 1, 'requested_at' => now(), 'detail' => 'Llantas', 'stage' => 'ordered',
            'supplier_id' => $supplier->id, 'amount' => $amount, 'currency' => 'PEN', 'installments' => $installments,
            'purchase_order_number' => 'OC-000001', 'has_detraction' => $detraction,
            'invoice_number' => 'F001-1', 'invoice_date' => now()->toDateString(), 'invoice_path' => "finance/{$tenantId}/invoices/f.pdf",
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
        // La persona designada para autorizar pagos
        Signatory::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'user_id' => $manager->id, 'position' => 'Gerente general', 'can_authorize' => true]);
        $r = $this->orderedRequisition($acme->id, 1000);
        $p = PaymentInstallment::withoutGlobalScopes()->sole();
        $account = CompanyBankAccount::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'bank' => 'BCP', 'account_number' => '191-000', 'currency' => 'PEN']);

        $this->actingAs($accountant)->get('/treasury')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Finance/Pages/Treasury/Index')->where('counts.to_schedule', 1)->where('kpis.pending', ['PEN' => 1000]));

        // Sin programar no se autoriza; sin autorizar no se paga
        $this->actingAs($manager)->post("/treasury/installments/{$p->id}/authorize")->assertStatus(422);
        $this->actingAs($accountant)->post("/treasury/installments/{$p->id}/schedule", ['scheduled_for' => now()->addDays(5)->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($accountant)->post("/treasury/installments/{$p->id}/pay", [])->assertStatus(422);

        // Solo autoriza la persona designada (apoderado con "autoriza pagos")
        $this->actingAs($this->makeUser($acme, 'logistics'))->post("/treasury/installments/{$p->id}/authorize")->assertForbidden();
        $this->actingAs($this->makeUser($acme, 'manager'))->post("/treasury/installments/{$p->id}/authorize")->assertForbidden();
        $this->actingAs($manager)->post("/treasury/installments/{$p->id}/authorize")->assertSessionHasNoErrors();
        // Gerencia autoriza pero no paga; quien autorizó tampoco registra el pago
        $self = $this->makeUser($acme, 'accountant');
        $q = PaymentInstallment::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'requisition_id' => $r->id, 'installment' => 2, 'amount' => 1, 'status' => 'pending', 'scheduled_for' => now()->toDateString(), 'authorized_by_id' => $self->id, 'authorized_at' => now()]);
        $this->actingAs($self)->post("/treasury/installments/{$q->id}/pay", ['paid_at' => now()->toDateString(), 'amount' => 1, 'company_bank_account_id' => $account->id, 'operation_number' => 'X', 'voucher' => $this->fakePdf('x.pdf')])->assertForbidden();
        $q->delete();
        $this->actingAs($manager)->post("/treasury/installments/{$p->id}/pay", [])->assertForbidden();

        $pay = fn (array $over = []) => array_merge([
            'paid_at' => now()->toDateString(), 'amount' => 400, 'company_bank_account_id' => $account->id, 'operation_number' => 'OP-1',
        ], $over);

        // No se paga más que el saldo, ni sin voucher del depósito
        $this->actingAs($accountant)->post("/treasury/installments/{$p->id}/pay", $pay(['amount' => 1000.01, 'voucher' => $this->fakePdf('v0.pdf')]))->assertSessionHasErrors('amount');
        $this->actingAs($accountant)->post("/treasury/installments/{$p->id}/pay", $pay())->assertSessionHasErrors('voucher');

        $this->actingAs($accountant)->post("/treasury/installments/{$p->id}/pay", $pay(['voucher' => $this->fakePdf('v1.pdf')]))
            ->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertSame(['pending', '400.00'], [$p->status, $p->paid_amount]);
        $this->assertTrue(PaymentCancellation::withoutGlobalScopes()->first()->is_amortization);

        // Bucle de amortización: el saldo vuelve a la cola de OP (programar y autorizar otra vez)
        $this->assertSame([null, null], [$p->scheduled_for, $p->authorized_at]);
        $this->actingAs($accountant)->post("/treasury/installments/{$p->id}/pay", $pay(['amount' => 600, 'voucher' => $this->fakePdf('v2.pdf')]))->assertStatus(422);
        $this->actingAs($accountant)->post("/treasury/installments/{$p->id}/schedule", ['scheduled_for' => now()->addDays(10)->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post("/treasury/installments/{$p->id}/authorize")->assertSessionHasNoErrors();
        $this->actingAs($accountant)->get("/treasury/installments/{$p->id}/op.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($accountant)->post("/treasury/installments/{$p->id}/pay", $pay(['amount' => 600, 'operation_number' => 'OP-2', 'voucher' => $this->fakePdf('v2.pdf')]))->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertSame(['paid', '1000.00'], [$p->status, $p->paid_amount]);
        $this->assertSame('paid', $r->fresh()->stage, 'todas las cuotas pagadas: requerimiento pagado');

        // El voucher se guarda en el disco privado y solo se sirve con permiso
        $first = PaymentCancellation::withoutGlobalScopes()->orderBy('id')->first();
        Storage::disk('private')->assertExists($first->voucher_path);
        $this->actingAs($accountant)->get("/treasury/payments/{$first->id}/voucher")->assertOk();
        $this->assertMatchesRegularExpression('/^(?=.*private)(?=.*no-store)(?!.*public)/', $this->actingAs($accountant)->get("/treasury/payments/{$first->id}/voucher")->headers->get('Cache-Control'));
        $this->actingAs($this->makeUser($acme, 'logistics'))->get("/treasury/payments/{$first->id}/voucher")->assertForbidden();

        // Cuota pagada ya no acepta pagos
        $this->actingAs($accountant)->post("/treasury/installments/{$p->id}/pay", $pay())->assertStatus(422);
    }

    public function test_treasury_sets_cash_flow_and_corrects_the_amount_of_an_unpaid_order(): void
    {
        Storage::fake('private');
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $accountant = $this->makeUser($acme, 'accountant');
        $r = $this->orderedRequisition($acme->id, 1000, 2);

        // Concepto del flujo: solo de la lista, y también se puede corregir después
        $this->actingAs($accountant)->put("/treasury/orders/{$r->id}/cash-flow", ['cash_flow_concept' => 'Inventado'])->assertSessionHasErrors('cash_flow_concept');
        $this->put("/treasury/orders/{$r->id}/cash-flow", ['cash_flow_concept' => 'Pago de regalías'])->assertSessionHasNoErrors();
        $this->assertSame('Pago de regalías', $r->fresh()->cash_flow_concept);

        // Corregir monto (sisgere actualizar_monto_requerim): rehace las cuotas, mismo número, sin autorización
        $first = PaymentInstallment::withoutGlobalScopes()->orderBy('installment')->first();
        $first->update(['scheduled_for' => now()->toDateString(), 'authorized_at' => now()]);
        $this->put("/treasury/orders/{$r->id}/amount", ['amount' => 1300])->assertSessionHasErrors('reason');
        $this->put("/treasury/orders/{$r->id}/amount", ['amount' => 1300, 'reason' => 'Factura con flete'])->assertSessionHasNoErrors();

        $rows = PaymentInstallment::withoutGlobalScopes()->where('requisition_id', $r->id)->orderBy('installment')->get();
        $this->assertSame(['650.00', '650.00'], $rows->pluck('amount')->all());
        $this->assertTrue($rows->every(fn ($p) => $p->authorized_at === null && $p->scheduled_for === null));
        $this->assertSame(['1300.00', '1300.00'], [$r->fresh()->amount, $r->fresh()->amount_payable]);

        // Con un pago registrado ya no se corrige
        $rows[0]->update(['paid_amount' => 100]);
        $this->put("/treasury/orders/{$r->id}/amount", ['amount' => 900, 'reason' => 'x'])->assertSessionHasErrors('amount');
        $this->assertSame('1300.00', $r->fresh()->amount);

        $this->actingAs($this->makeUser($acme, 'logistics'))->put("/treasury/orders/{$r->id}/amount", ['amount' => 1, 'reason' => 'x'])->assertForbidden();
    }

    public function test_two_simultaneous_payments_do_not_pay_the_installment_twice(): void
    {
        Storage::fake('private');
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $accountant = $this->makeUser($acme, 'accountant');
        $this->orderedRequisition($acme->id, 1000);
        $p = PaymentInstallment::withoutGlobalScopes()->sole();
        $p->update(['scheduled_for' => now()->toDateString(), 'authorized_by_id' => $this->makeUser($acme, 'manager')->id, 'authorized_at' => now()]);
        $account = CompanyBankAccount::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'bank' => 'BCP', 'account_number' => '191-000', 'currency' => 'PEN']);
        $this->actingAs($accountant);
        app(TenantContext::class)->set($acme);

        // Doble clic o dos tesoreros: las dos solicitudes ya validaron con el saldo de 1000 antes de que
        // cualquiera guarde. El registro (con la cuota bloqueada) debe aceptar solo la primera
        $stale = PaymentInstallment::withoutGlobalScopes()->findOrFail($p->id);
        $register = new \ReflectionMethod(\App\Modules\Finance\Controllers\Web\TreasuryWebController::class, 'registerPayment');
        $controller = app(\App\Modules\Finance\Controllers\Web\TreasuryWebController::class);
        $data = ['paid_at' => now()->toDateString(), 'amount' => 1000, 'operation_number' => 'OP-1'];
        $request = request()->setUserResolver(fn () => $accountant);

        $this->assertTrue($register->invoke($controller, $stale, $data, $account, 'v1.pdf', $request));
        try {
            $register->invoke($controller, $stale, ['operation_number' => 'OP-2'] + $data, $account, 'v2.pdf', $request);
            $this->fail('El segundo pago no debía registrarse');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame('Esta cuota ya se pagó. Actualiza la pantalla.', $e->errors()['amount'][0]);
        }
        $this->assertSame(1, PaymentCancellation::withoutGlobalScopes()->count());
        $this->assertSame(['paid', '1000.00'], [$p->fresh()->status, $p->fresh()->paid_amount]);
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

    public function test_treasury_totals_are_per_currency_and_per_company(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $accountant = $this->makeUser($acme, 'accountant');
        $owe = function ($tenant, string $currency, float $amount, float $paid = 0) {
            $r = Requisition::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'series' => '001', 'number' => random_int(1, 99999), 'year' => 2026,
                'requested_at' => now(), 'detail' => "Orden {$currency}", 'stage' => 'ordered', 'currency' => $currency, 'amount' => $amount]);
            PaymentInstallment::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'requisition_id' => $r->id, 'installment' => 1,
                'due_date' => now()->subDay()->toDateString(), 'amount' => $amount, 'paid_amount' => $paid, 'status' => 'pending']);
        };
        $owe($acme, 'PEN', 1000, 200);
        $owe($acme, 'USD', 300);
        $owe($other, 'PEN', 5000); // otra empresa: no entra

        // Antes: S/ 1,100 (soles y dólares sumados); ahora cada moneda por separado
        $this->actingAs($accountant)->get('/treasury')->assertInertia(fn ($p) => $p
            ->where('kpis.pending', ['PEN' => 800, 'USD' => 300])->where('kpis.overdue', ['PEN' => 800, 'USD' => 300]));
    }

    public function test_treasury_filters_like_frmregpagos_dates_payroll_and_employee_search(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $accountant = $this->makeUser($acme, 'accountant');
        $ana = $this->makeUser($acme, 'employee', ['first_name' => 'Ana', 'last_name' => 'Quispe', 'document_number' => '45871236']);
        $due = function (string $date, array $extra = []) use ($acme) {
            $r = Requisition::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'series' => '001', 'number' => random_int(1, 99999), 'year' => 2026,
                'requested_at' => now(), 'detail' => 'Cuota ' . $date, 'stage' => 'ordered', 'currency' => 'PEN', 'amount' => 100, 'invoice_number' => 'F001-77'] + $extra);
            PaymentInstallment::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'requisition_id' => $r->id, 'installment' => 1,
                'due_date' => $date, 'amount' => 100, 'paid_amount' => 0, 'status' => 'pending']);
        };
        $due('2026-11-05');
        $due('2026-11-20');
        $due('2026-11-10', ['employee_id' => $ana->id, 'payroll_concept' => 'HABERES', 'payroll_period' => '2026-11']);
        $get = fn (string $query) => collect($this->actingAs($accountant)->get("/treasury?tab=to_schedule{$query}")->assertOk()->viewData('page')['props']['installments']['data'])->pluck('requisition.detail')->all();

        $this->assertSame(['Cuota 2026-11-05', 'Cuota 2026-11-10', 'Cuota 2026-11-20'], $get(''));
        $this->assertSame(['Cuota 2026-11-05', 'Cuota 2026-11-10'], $get('&from=2026-11-01&to=2026-11-15'));     // Desde / Hasta
        $this->assertSame(['Cuota 2026-11-05', 'Cuota 2026-11-20'], $get('&payroll=0'));                         // sin planilla
        $this->assertSame(['Cuota 2026-11-10'], $get('&search=Quispe'));                                          // Buscar Prov.: colaborador
        $this->assertSame(['Cuota 2026-11-10'], $get('&search=4587'));
        $this->assertSame(['Cuota 2026-11-05', 'Cuota 2026-11-10', 'Cuota 2026-11-20'], $get('&from=nope'));    // fecha inválida: se ignora
        $this->actingAs($accountant)->get('/treasury?tab=to_schedule')->assertInertia(fn ($p) => $p->where('installments.data.0.requisition.invoice_number', 'F001-77'));
        $this->get('/treasury/export?tab=to_schedule&payroll=0')->assertOk()->assertDownload();
    }
}
