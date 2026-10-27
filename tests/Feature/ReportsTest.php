<?php

namespace Tests\Feature;

use App\Modules\Finance\Controllers\Web\ReportWebController;
use App\Modules\Finance\Models\CompanyBankAccount;
use App\Modules\Finance\Models\PaymentCancellation;
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

        // RRHH ve el de colaboradores; un supervisor no ve ninguno
        $hr = $this->makeUser($acme, 'hr_manager');
        $rows = $this->sheet($this->actingAs($hr)->get("/finance-reports/employees{$range}")->assertOk());
        $this->assertSame('DNI', $rows[0][0]);
        $this->actingAs($this->makeUser($acme, 'supervisor'))->get('/finance-reports')->assertForbidden();
    }
}
