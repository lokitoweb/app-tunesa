<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Finance\Models\CompanyBankAccount;
use App\Modules\Finance\Models\PaymentInstallment;
use App\Modules\Finance\Models\Product;
use App\Modules\Finance\Models\Requisition;
use App\Modules\Finance\Models\Signatory;
use App\Modules\Finance\Models\StockMovement;
use App\Modules\Finance\Models\Supplier;
use App\Modules\Finance\Notifications\SupplierDocument;
use App\Modules\HR\Models\EmployeeProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Pagos de RRHH: planilla importada por DNI -> Tesorería (misma autorización, voucher y amortización) */
class PayrollPaymentsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function xlsx(array $rows): UploadedFile
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([['DNI', 'Colaborador', 'Monto neto a pagar'], ...$rows]);
        $path = tempnam(sys_get_temp_dir(), 'pl') . '.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'planilla.xlsx', null, null, true);
    }

    private function employee($tenant, string $dni, string $status = 'verified', array $profile = []): User
    {
        $user = $this->makeUser($tenant, 'employee', ['document_number' => $dni, 'email' => "e{$dni}@acme.test"]);
        EmployeeProfile::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'bank_name' => 'BCP', 'bank_account_number' => "191{$dni}",
            'cts_bank_name' => 'Interbank', 'cts_account_number' => "898{$dni}", 'payment_info_status' => $status,
        ], $profile));

        return $user;
    }

    public function test_payroll_import_is_all_or_nothing_and_payments_flow_through_treasury(): void
    {
        Storage::fake('private');
        Notification::fake();
        $this->seedRoles();
        $acme = $this->makeTenant('acme', ['company_name' => 'Turismo Negreiros']);
        $other = $this->makeTenant('other');
        $hr = $this->makeUser($acme, 'hr_manager');
        $accountant = $this->makeUser($acme, 'accountant');
        $manager = $this->makeUser($acme, 'manager');
        Signatory::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'user_id' => $manager->id, 'position' => 'Gerente', 'can_authorize' => true]);
        $ana = $this->employee($acme, '45871236');
        $luis = $this->employee($acme, '40000002');
        $this->employee($acme, '40000003', 'pending'); // cuenta sin verificar
        $this->employee($other, '40000004');            // otra empresa
        $import = fn (array $rows, array $extra = []) => $this->actingAs($hr)->post('/payroll-payments/import',
            $extra + ['period' => '2026-10', 'concept' => 'HABERES', 'due_date' => '2026-10-30', 'file' => $this->xlsx($rows)]);

        // Una fila mala: no entra nada y se indica cada fila
        $import([['45871236', 'Ana', 2350.75], ['4000', 'X', 10], ['40000003', 'Pedro', 100], ['40000004', 'Ajeno', 100], ['45871236', 'Ana otra vez', 1], ['40000002', 'Luis', 0]])
            ->assertSessionHasErrors('file');
        $errors = session('errors')->get('file')[0];
        foreach (['Fila 3: el DNI «4000» debe tener 8 dígitos', 'Fila 4: la cuenta del legajo', 'Fila 5: no hay un colaborador con DNI 40000004', 'Fila 6: el DNI 45871236 está repetido', 'Fila 7: el monto'] as $expected) {
            $this->assertStringContainsString($expected, $errors);
        }
        $this->assertSame(0, Requisition::withoutGlobalScopes()->whereNotNull('payroll_concept')->count());

        // Un RQ ya emitido en la misma serie y año: el PL lleva su propio correlativo sin chocar
        \App\Modules\Finance\Models\DocumentSequence::next($acme, 'RQ', '001', (int) now()->year);
        Requisition::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'series' => '001', 'number' => 1, 'year' => (int) now()->year,
            'code' => 'RQ-TN-001-000001-' . now()->year, 'requested_at' => now(), 'detail' => 'RQ previo', 'stage' => 'requested']);

        $import([['45871236', 'Ana', 2350.75], ['40000002', 'Luis', '1,800.00']])->assertSessionHasNoErrors();
        $payments = Requisition::withoutGlobalScopes()->whereNotNull('payroll_concept')->orderBy('id')->get();
        $this->assertSame(['2350.75', '1800.00'], $payments->pluck('amount')->all());
        $this->assertSame([$ana->id, $luis->id], $payments->pluck('employee_id')->all());
        $this->assertStringStartsWith('PL-TN-', $payments[0]->orderNumber());
        $this->assertSame('2026-10-30', PaymentInstallment::withoutGlobalScopes()->where('requisition_id', $payments[0]->id)->sole()->due_date->toDateString());

        // El mismo periodo y concepto no se paga dos veces; otro concepto sí (CTS a la cuenta CTS)
        $import([['45871236', 'Ana', 2350.75]])->assertSessionHasErrors('file');
        $import([['45871236', 'Ana', 1200]], ['concept' => 'CTS'])->assertSessionHasNoErrors();
        $this->assertSame('89845871236', Requisition::withoutGlobalScopes()->where('payroll_concept', 'CTS')->sole()->payrollAccount()['number']);

        // Sueldos fuera de Requerimientos; en la pantalla de RRHH con estado y posibles bajas
        $this->actingAs($accountant)->get('/requisitions')->assertInertia(fn ($p) => $p->has('requisitions.data', 1)->where('requisitions.data.0.detail', 'RQ previo')); // solo el RQ, ningún sueldo
        $this->actingAs($hr)->get('/payroll-payments?period=2026-10')->assertOk()->assertInertia(fn ($p) => $p
            ->has('payments', 3)->where('totals.HABERES.total', 4150.75)->where('payments.1.concept', 'HABERES')->where('payments.1.status', 'Programado')->where('payments.1.account', 'BCP ••••••1236'));

        // Tesorería: programar, autoriza la persona designada, paga sin factura pero con voucher
        $p = PaymentInstallment::withoutGlobalScopes()->where('requisition_id', $payments[0]->id)->sole();
        $account = CompanyBankAccount::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'bank' => 'BCP', 'account_number' => '191-9', 'currency' => 'PEN']);
        $this->assertSame(now(tenant_timezone())->toDateString(), $p->scheduled_for->toDateString());
        $this->actingAs($accountant)->get('/treasury?tab=scheduled')->assertInertia(fn ($pg) => $pg->where('installments.data.0.supplier.ruc', 'DNI 45871236')
            ->where('installments.data.0.supplier.accounts.0', 'BCP 19145871236')->where('installments.data.0.requisition.has_invoice', true));
        $this->actingAs($accountant)->post("/treasury/installments/{$p->id}/schedule", ['scheduled_for' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post("/treasury/installments/{$p->id}/authorize")->assertSessionHasNoErrors();
        $this->actingAs($accountant)->get("/treasury/installments/{$p->id}/op.pdf")->assertOk();

        $pay = ['paid_at' => now()->toDateString(), 'company_bank_account_id' => $account->id, 'operation_number' => 'PL-1', 'voucher' => $this->fakePdf('v.pdf')];
        $this->actingAs($accountant)->post("/treasury/installments/{$p->id}/pay", $pay + ['amount' => 2350.75])->assertSessionHasNoErrors();
        $this->assertSame('paid', $payments[0]->fresh()->stage);
        Notification::assertSentTo(new AnonymousNotifiable, SupplierDocument::class,
            fn ($n, $c, $to) => $to->routes['mail'] === 'e45871236@acme.test' && $n->greeting === "Hola, {$ana->first_name}:" && str_starts_with($n->subject, 'Pago cancelado'));

        // Si RRHH deja de tener la cuenta verificada, no se paga
        $q = PaymentInstallment::withoutGlobalScopes()->where('requisition_id', $payments[1]->id)->sole();
        $q->update(['scheduled_for' => now(), 'authorized_by_id' => $manager->id, 'authorized_at' => now()]);
        EmployeeProfile::withoutGlobalScopes()->where('user_id', $luis->id)->update(['payment_info_status' => 'pending']);
        $this->actingAs($accountant)->post("/treasury/installments/{$q->id}/pay", $pay + ['amount' => 1800])->assertStatus(422);

        // Permisos: Logística no importa planillas
        $this->actingAs($this->makeUser($acme, 'logistics'))->get('/payroll-payments')->assertForbidden();
        $this->actingAs($accountant)->get('/payroll-payments/template')->assertOk()->assertDownload('plantilla-planilla.xlsx');
    }

    public function test_planilla_screen_has_the_desktop_columns_totals_and_collaborators_report_per_company(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme', ['company_name' => 'Turismo Negreiros']);
        $other = $this->makeTenant('other');
        $hr = $this->makeUser($acme, 'hr_manager');
        $otherHr = $this->makeUser($other, 'hr_manager');
        $trujillo = $this->makeBranch($acme, ['name' => 'Sede Centro', 'city' => 'TRUJILLO']);
        $ana = $this->employee($acme, '45871236', 'verified', ['interbank_code' => '00219100458712365555']);
        $ana->update(['position' => 'CONDUCTOR', 'scope_branch_id' => $trujillo->id]);
        $luis = $this->employee($acme, '40000002');
        $this->employee($other, '40000004'); // otra empresa: ni en la grilla ni en el reporte
        $this->actingAs($hr)->post('/payroll-payments/import', ['period' => '2026-09', 'concept' => 'HABERES', 'due_date' => '2026-09-30',
            'file' => $this->xlsx([['45871236', 'Ana', 1500], ['40000002', 'Luis', 1000]])])->assertSessionHasNoErrors();
        $first = Requisition::withoutGlobalScopes()->where('employee_id', $ana->id)->sole();
        PaymentInstallment::withoutGlobalScopes()->where('requisition_id', $first->id)->update(['status' => 'paid', 'paid_amount' => 1500, 'paid_at' => '2026-09-30 10:00:00']);
        $first->update(['stage' => 'paid']);

        // sisgere FrmRegrrhh › Planilla: # Operación, Colaborador, Orden Pago, F. Prog, F. Pago, Ciudad, Cargo, Monto a depositar, Banco, CCI
        $this->actingAs($hr)->get('/payroll-payments?period=2026-09')->assertInertia(fn ($p) => $p
            ->where('summary', ['employees' => 2, 'accumulated' => 2500, 'to_pay' => 1000])
            ->where('payments.0.code', $first->code())->where('payments.0.order', 'OP-' . $first->code() . '-01')
            ->where('payments.0.scheduled_for', now(tenant_timezone())->format('d/m/Y'))->where('payments.0.paid_at', '30/09/2026')
            ->where('payments.0.city', 'TRUJILLO')->where('payments.0.position', 'CONDUCTOR')->where('payments.0.amount', 1500)
            // RRHH verifica la cuenta en el legajo: la ve completa (CCI si hay, si no la cuenta)
            ->where('payments.0.bank', 'BCP')->where('payments.0.cci', '00219100458712365555')
            ->where('payments.1.city', null)->where('payments.1.cci', '19140000002')->where('payments.1.paid_at', null)
            ->where('can.treasury', false));
        $this->actingAs($hr)->get('/payroll-payments?period=2026-08')->assertInertia(fn ($p) => $p->has('payments', 0)->where('summary.employees', 0));

        // Cada empresa tiene su planilla: el mismo periodo en otra empresa no choca ni se suma (el .NET bloqueaba el periodo para todas)
        $this->actingAs($otherHr)->post('/payroll-payments/import', ['period' => '2026-09', 'concept' => 'HABERES', 'due_date' => '2026-09-30',
            'file' => $this->xlsx([['40000004', 'Ajeno', 700]])])->assertSessionHasNoErrors();
        $this->actingAs($otherHr)->get('/payroll-payments?period=2026-09')->assertInertia(fn ($p) => $p->has('payments', 1)
            ->where('summary', ['employees' => 1, 'accumulated' => 700, 'to_pay' => 700]));
        $this->actingAs($hr)->get('/payroll-payments?period=2026-09')->assertInertia(fn ($p) => $p->has('payments', 2)->where('summary.accumulated', 2500));

        // Reportes: el listado de colaboradores existente (Crcolaboradores) con código, dirección, tipo y CCI, solo de la empresa
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($this->actingAs($hr)->get('/finance-reports/employees?from=2026-09-01&to=2026-09-30&regime=all')
            ->assertOk()->getFile()->getPathname())->getActiveSheet()->toArray();
        $this->assertSame(['Código', 'Dirección', 'Tipo de cuenta', 'CCI (últimos 4)'], [$sheet[0][2], $sheet[0][7], $sheet[0][11], $sheet[0][13]]);
        $dnis = array_column(array_slice($sheet, 1), 0);
        $this->assertContains('45871236', $dnis);
        $this->assertNotContains('40000004', $dnis);
        $this->assertContains('••••••5555', array_column(array_slice($sheet, 1), 13));
    }

    public function test_purchases_by_product_uses_kardex_cost_or_prorated_order_amount(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $branch = $this->makeBranch($acme);
        $supplier = Supplier::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'name' => 'Llantas Sur', 'ruc' => '20111111111']);
        $tire = Product::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'name' => 'Llanta 295', 'unit' => 'UNIDAD']);
        $valve = Product::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'name' => 'Válvula', 'unit' => 'UNIDAD']);
        $order = function (string $number, float $amount, array $lines) use ($acme, $supplier) {
            $r = Requisition::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'series' => '001', 'number' => (int) substr($number, -1), 'requested_at' => now(),
                'detail' => 'x', 'stage' => 'ordered', 'ordered_at' => now(), 'supplier_id' => $supplier->id, 'amount' => $amount, 'currency' => 'PEN', 'purchase_order_number' => $number]);
            foreach ($lines as [$product, $qty]) {
                $r->items()->create(['product_id' => $product->id, 'quantity' => $qty]);
            }

            return $r;
        };
        $received = $order('OC-1', 1300, [[$tire, 4], [$valve, 1]]);
        $order('OC-2', 600, [[$tire, 2], [$valve, 2]]);
        StockMovement::record(['tenant_id' => $acme->id, 'product_id' => $tire->id, 'branch_id' => $branch->id, 'type' => 'in', 'quantity' => 4, 'unit_cost' => 310, 'requisition_id' => $received->id]);

        $this->actingAs($this->makeUser($acme, 'logistics'))->get("/purchases/by-product?product={$tire->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->has('rows', 2)
                ->where('rows', fn ($rows) => collect($rows)->firstWhere('order', 'OC-1')['unit_cost'] == 310 && collect($rows)->firstWhere('order', 'OC-1')['source'] === 'kardex'
                    && collect($rows)->firstWhere('order', 'OC-2')['unit_cost'] == 150 && collect($rows)->firstWhere('order', 'OC-2')['source'] === 'orden')
                ->where('summary.quantity', 6));
        $this->actingAs($this->makeUser($acme, 'employee'))->get('/purchases/by-product')->assertRedirect(); // sin acceso al panel
    }
}
