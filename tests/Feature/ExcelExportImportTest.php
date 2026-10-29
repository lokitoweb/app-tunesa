<?php

namespace Tests\Feature;

use App\Modules\Core\Support\SpreadsheetRows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Exportar grillas a Excel (FrmReqProg) e importar ítems del requerimiento (FrmEXCEL) */
class ExcelExportImportTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_export_keeps_formula_like_text_as_text(): void
    {
        $path = tempnam(sys_get_temp_dir(), 't') . '.xlsx';
        SpreadsheetRows::export($path, 'Prueba: [x]', ['A', 'B'], [['=1+1', 12.5]]);
        $sheet = IOFactory::load($path)->getActiveSheet();
        $this->assertSame('=1+1', $sheet->getCell('A2')->getValue());
        $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
        $this->assertSame(12.5, $sheet->getCell('B2')->getValue());
        @unlink($path);
    }

    public function test_treasury_and_fixed_costs_export_and_item_import(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $accountant = $this->makeUser($acme, 'accountant');

        $this->actingAs($accountant)->get('/treasury/export?tab=paid')->assertOk()->assertDownload();
        $this->get('/fixed-costs?export=1')->assertOk()->assertDownload('costos-fijos-' . now(tenant_timezone())->format('Y-m') . '.xlsx');
        $this->actingAs($this->makeUser($acme, 'logistics'))->get('/treasury/export')->assertForbidden();

        // Ítems desde Excel: lee nombre/cantidad/unidad y reporta las filas malas
        $requester = $this->makeUser($acme, 'supervisor');
        $file = tempnam(sys_get_temp_dir(), 'i') . '.csv';
        file_put_contents($file, "Producto,Cantidad,Unidad,Justificación\nLlanta 295,4,unidad (bienes),Desgaste\nAceite,2,GALON,\n");
        $this->actingAs($requester)->post('/requisitions/items/parse', ['file' => new UploadedFile($file, 'items.csv', 'text/csv', null, true)])
            ->assertOk()->assertJson(['items' => [
                ['product' => 'Llanta 295', 'quantity' => 4, 'unit' => 'UNIDAD (BIENES)', 'justification' => 'Desgaste'],
                ['product' => 'Aceite', 'quantity' => 2, 'unit' => 'GALON'],
            ]]);

        // Plantilla del ERP de escritorio (DETALLE_DOC.xlsx): PRODUCTO, UNIDAD MED, CANTIDAD, CLASIFICACION, PLACA / REF, CODE
        file_put_contents($file, "PRODUCTO,UNIDAD MED,CANTIDAD,CLASIFICACION,PLACA / REF,CODE\nFiltro de petroleo,UNIDAD (BIENES),1,REPUESTOS,T6C-831,0\n");
        $this->postJson('/requisitions/items/parse', ['file' => new UploadedFile($file, 'DETALLE_DOC.csv', 'text/csv', null, true)])
            ->assertOk()->assertJson(['items' => [['product' => 'Filtro de petroleo', 'quantity' => 1, 'unit' => 'UNIDAD (BIENES)', 'justification' => 'T6C-831']]]);

        file_put_contents($file, "Producto,Cantidad\nLlanta,0\n,3\n");
        $this->postJson('/requisitions/items/parse', ['file' => new UploadedFile($file, 'bad.csv', 'text/csv', null, true)])
            ->assertStatus(422)->assertJsonPath('errors.file.0', 'Fila 2: la cantidad debe ser un número mayor a cero. Fila 3: falta el producto.');
        $this->get('/requisitions/items/template')->assertOk()->assertDownload('plantilla-items-requerimiento.xlsx');
        @unlink($file);
    }
}
