<?php

namespace Tests\Feature;

use App\Modules\Finance\Controllers\Web\ReportWebController;
use App\Modules\Finance\Models\CompanyBankAccount;
use App\Modules\Finance\Models\PaymentCancellation;
use App\Modules\Finance\Models\PettyCashExpense;
use App\Modules\Finance\Models\Requisition;
use App\Modules\Finance\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Reportes en Excel equivalentes a los Crystal Reports de sisgere */
class ReportsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function sheet($response): array
    {
        $path = $response->baseResponse->getFile()->getPathname();

        return IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
    }

    public function test_every_report_downloads_with_its_data_and_permissions(): void
    {
        Storage::fake('private');
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $accountant = $this->makeUser($acme, 'accountant');
        $supplier = Supplier::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'name' => 'Taller Norte', 'ruc' => '20222222222']);
        $account = CompanyBankAccount::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'bank' => 'BCP', 'account_number' => '191-1', 'currency' => 'PEN', 'is_active' => true]);

        // Un pago directo programado (anticipo) y otro pagado
        $base = ['supplier_id' => $supplier->id, 'detail' => 'Reparación', 'document_type' => 'ORDP', 'document_date' => now()->toDateString(),
            'currency' => 'PEN', 'due_date' => now()->toDateString(), 'payment_method' => 'TRANSFERENCIA'];
        $this->actingAs($accountant)->post('/treasury/direct', $base + ['amount' => 500, 'is_advance' => true])->assertSessionHasNoErrors();
        $this->post('/treasury/direct', $base + ['amount' => 300])->assertSessionHasNoErrors();
        [$adv, $paid] = Requisition::withoutGlobalScopes()->orderBy('id')->get()->map->paymentInstallments->map->first()->all();
        $adv->update(['scheduled_for' => now()->toDateString()]);
        $paid->update(['status' => 'paid', 'paid_amount' => 300, 'paid_at' => now()]);
        PaymentCancellation::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'payment_installment_id' => $paid->id, 'company_bank_account_id' => $account->id,
            'paid_at' => now(), 'amount' => 300, 'bank' => 'BCP', 'account_number' => '191-1', 'operation_number' => 'OP-9', 'is_amortization' => false]);

        $this->get('/finance-reports')->assertOk()->assertInertia(fn ($p) => $p->component('Finance/Pages/Reports/Index')
            ->where('reports', fn ($r) => collect($r)->pluck('key')->contains('scheduled') && ! collect($r)->pluck('key')->contains('employees')));

        $range = '?from=' . now()->startOfMonth()->toDateString() . '&to=' . now()->endOfMonth()->toDateString();
        $this->get("/finance-reports/scheduled?from=2026-12-01&to=2026-01-01")->assertSessionHasErrors('to');
        foreach (array_keys(ReportWebController::REPORTS) as $key) {
            if ($key === 'employees') {
                $this->get("/finance-reports/{$key}{$range}")->assertForbidden(); // contabilidad no ve el reporte de personal
                continue;
            }
            $this->get("/finance-reports/{$key}{$range}")->assertOk()->assertDownload();
        }

        $this->assertSame('500', (string) $this->sheet($this->get("/finance-reports/scheduled{$range}"))[1][8]);
        $byBank = $this->sheet($this->get("/finance-reports/payments_by_bank{$range}"));
        $this->assertSame(['BCP', '191-1'], [$byBank[1][0], $byBank[1][1]]);
        $this->assertSame('TOTAL BCP 191-1', $byBank[2][0]);
        $this->assertSame('Por pagar', $this->sheet($this->get("/finance-reports/advances{$range}"))[1][5]); // programado, aún sin pagar
        $this->assertCount(3, $this->sheet($this->get("/finance-reports/summary{$range}"))); // encabezado + 2

        // Reporte Pago General › Pendiente (CRprogpagos): programadas y sin programar juntas; la pagada no
        $adv->replicate()->fill(['installment' => 2, 'scheduled_for' => null])->save();
        $pending = $this->sheet($this->get("/finance-reports/pending{$range}"));
        $this->assertCount(3, $pending);
        $this->assertSame([now()->format('d/m/Y'), null], [$pending[1][5], $pending[2][5]]);

        // Caja chica en detalle (CRcaja_chica): cada gasto con su recibo
        PettyCashExpense::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'receipt_number' => 4, 'date' => now()->toDateString(), 'description' => 'Movilidad',
            'document_type' => 'BOLETA', 'document_number' => 'B001-7', 'amount' => 12.5, 'cash_box' => 'CAJA EFECTIVO']);
        $this->assertSame([4, 'CAJA EFECTIVO', 'Movilidad', 'B001-7', 12.5], array_values(array_intersect_key($this->sheet($this->get("/finance-reports/petty_cash_detail{$range}"))[1], array_flip([0, 2, 3, 8, 9]))));

        // Centro de costos: con placa solo ese vehículo (crcentro_c)
        Requisition::withoutGlobalScopes()->whereKey($adv->requisition_id)->update(['plate' => 't6c-831', 'stage' => 'ordered', 'ordered_at' => now()]);
        Requisition::withoutGlobalScopes()->whereKey($paid->requisition_id)->update(['plate' => 'ABC-123', 'stage' => 'paid', 'ordered_at' => now()]);
        $this->assertCount(3, $this->sheet($this->get("/finance-reports/cost_center{$range}")));
        $one = $this->sheet($this->get("/finance-reports/cost_center{$range}&plate=T6C-831"));
        $this->assertSame([2, 'T6C-831'], [count($one), $one[1][0]]);

        // RRHH ve el de colaboradores; un supervisor no ve ninguno
        $hr = $this->makeUser($acme, 'hr_manager');
        $rows = $this->sheet($this->actingAs($hr)->get("/finance-reports/employees{$range}")->assertOk());
        $this->assertSame('DNI', $rows[0][0]);
        $this->actingAs($this->makeUser($acme, 'supervisor'))->get('/finance-reports')->assertForbidden();
    }

    public function test_staff_list_for_inspection_has_only_payroll_unless_fee_earners_are_asked_for(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $hr = $this->makeUser($acme, 'hr_manager', ['last_name' => 'AAA']);
        $this->makeUser($acme, 'employee', ['last_name' => 'Planilla', 'document_number' => '11111111', 'contract_type' => 'full_time']);
        $this->makeUser($acme, 'employee', ['last_name' => 'Honorarios', 'document_number' => '22222222', 'contract_type' => 'contractor']);
        $dnis = fn (string $query) => collect(array_slice($this->sheet($this->actingAs($hr)->get("/finance-reports/employees?from=2026-10-01&to=2026-10-31{$query}")->assertOk()), 1))->pluck(0)->filter()->values()->all();

        // Por defecto (inspección): sin quien cobra por recibo por honorarios
        $this->assertContains('11111111', $dnis(''));
        $this->assertNotContains('22222222', $dnis(''));
        $this->assertSame(['22222222'], $dnis('&regime=honorarios'));
        $this->assertContains('22222222', $dnis('&regime=all'));
        $this->assertContains('11111111', $dnis('&regime=all'));

        // La lista del panel filtra igual
        $this->get('/employees?regime=honorarios', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertSee('22222222')->assertDontSee('11111111');
    }
}
