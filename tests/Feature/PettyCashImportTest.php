<?php

namespace Tests\Feature;

use App\Modules\Finance\Models\PettyCashExpense;
use App\Modules\Finance\Models\Supplier;
use App\Modules\Finance\Services\PettyCashImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Importación de caja chica desde Excel: mismas reglas del registro manual, todo o nada, sin duplicados */
class PettyCashImportTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function xlsx(array $rows): UploadedFile
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([array_keys(PettyCashImport::COLUMNS), ...$rows]);
        $path = tempnam(sys_get_temp_dir(), 'pc') . '.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'caja.xlsx', null, null, true);
    }

    public function test_imports_rows_with_sequential_receipts_and_rejects_bad_or_duplicate_files(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $accountant = $this->makeUser($acme, 'accountant');
        Supplier::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'name' => 'LIBRERÍA SUR SAC', 'ruc' => '20123456789']);
        PettyCashExpense::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'receipt_number' => 7, 'date' => '2026-10-01', 'description' => 'Previo',
            'document_type' => 'BOLETA', 'document_number' => 'B001-1', 'amount' => 10, 'cash_box' => 'CAJA EFECTIVO']);

        $good = [
            ['15/10/2026', 'Boleta', '20123456789', 'Útiles', 'Administración', 'PEN', 45.5, 'B001-000123'],
            [46310, 'FACTURA', 'Taxi Pérez', 'Movilidad', 'Ventas', 'S/', '1,200.00', 'F001-9'], // fecha como número de serie de Excel
            [],
        ];

        // Una fila mala (moneda) o un comprobante repetido: no entra nada y se dice la fila
        $this->actingAs($accountant)->post('/petty-cash/import', ['cash_box' => 'CAJA EFECTIVO', 'file' => $this->xlsx([...$good, ['16/10/2026', 'BOLETA', '', 'X', '', 'USD', 5, '']])])
            ->assertSessionHasErrors(['file' => 'Fila 5: caja chica es en soles (moneda «USD»).']);
        $this->actingAs($accountant)->post('/petty-cash/import', ['cash_box' => 'CAJA EFECTIVO', 'file' => $this->xlsx([...$good, ['16/10/2026', 'BOLETA', '', 'X', '', '', 5, 'B001-1']])])
            ->assertSessionHasErrors(['file' => 'Fila 5: el comprobante BOLETA B001-1 ya está registrado.']);
        $this->actingAs($accountant)->post('/petty-cash/import', ['cash_box' => 'CAJA EFECTIVO', 'file' => $this->xlsx([['31/02/2026', 'VALE', '', '', '', '', 0, '']])])
            ->assertSessionHasErrors('file');
        $this->assertSame(1, PettyCashExpense::withoutGlobalScopes()->count());

        $this->actingAs($accountant)->post('/petty-cash/import', ['cash_box' => 'CAJA PASAJES', 'file' => $this->xlsx($good)])
            ->assertSessionHasNoErrors()->assertSessionHas('success', '2 gastos importados (recibos N.º 8 al 9).');

        $rows = PettyCashExpense::withoutGlobalScopes()->where('receipt_number', '>', 7)->orderBy('receipt_number')->get();
        $this->assertSame(['2026-10-15', 'BOLETA', '20123456789', 'LIBRERÍA SUR SAC', 'ADMINISTRACIÓN', '45.50', 'CAJA PASAJES'],
            [$rows[0]->date->toDateString(), $rows[0]->document_type, $rows[0]->ruc, $rows[0]->beneficiary, $rows[0]->category, $rows[0]->amount, $rows[0]->cash_box]);
        $this->assertSame(['2026-10-15', null, 'Taxi Pérez', '1200.00'], [$rows[1]->date->toDateString(), $rows[1]->ruc, $rows[1]->beneficiary, $rows[1]->amount]);

        // El mismo archivo otra vez: duplicado
        $this->actingAs($accountant)->post('/petty-cash/import', ['cash_box' => 'CAJA PASAJES', 'file' => $this->xlsx($good)])->assertSessionHasErrors('file');

        // Plantilla y permisos
        $this->actingAs($accountant)->get('/petty-cash/template')->assertOk()->assertDownload('plantilla-caja-chica.xlsx');
        $this->actingAs($this->makeUser($acme, 'logistics'))->post('/petty-cash/import', ['cash_box' => 'CAJA PASAJES', 'file' => $this->xlsx($good)])->assertForbidden();
    }
}
