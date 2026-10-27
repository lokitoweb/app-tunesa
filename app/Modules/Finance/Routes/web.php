<?php

use App\Modules\Finance\Controllers\Web\CashFlowWebController;
use App\Modules\Finance\Controllers\Web\FixedCostWebController;
use App\Modules\Finance\Controllers\Web\FuelWebController;
use App\Modules\Finance\Controllers\Web\InventoryWebController;
use App\Modules\Finance\Controllers\Web\PayrollPaymentWebController;
use App\Modules\Finance\Controllers\Web\SignatoryWebController;
use App\Modules\Finance\Controllers\Web\VehicleWebController;
use App\Modules\Finance\Controllers\Web\PettyCashWebController;
use App\Modules\Finance\Controllers\Web\RequisitionWebController;
use App\Modules\Finance\Controllers\Web\SupplierWebController;
use App\Modules\Finance\Controllers\Web\TreasuryWebController;
use Illuminate\Support\Facades\Route;

// Finance (panel web): proveedores y sus cuentas bancarias
Route::middleware(['auth', 'web.tenant', 'can:suppliers.view'])->prefix('suppliers')->name('suppliers.')->group(function () {
    Route::get('/', [SupplierWebController::class, 'index'])->name('index');

    Route::middleware('can:suppliers.manage')->group(function () {
        Route::post('/', [SupplierWebController::class, 'store'])->name('store');
        Route::put('/{id}', [SupplierWebController::class, 'update'])->name('update')->whereNumber('id');
        Route::delete('/{id}', [SupplierWebController::class, 'destroy'])->name('destroy')->whereNumber('id');
        Route::get('/ruc/{ruc}', [SupplierWebController::class, 'lookupRuc'])->name('ruc')->where('ruc', '\d{11}')
            ->middleware('throttle:30,1');
    });
});

// Requerimientos: quien solicita los ve y edita (los suyos); gerencia aprueba; logística cotiza y emite OC/OS
Route::middleware(['auth', 'web.tenant', 'can:requisitions.create'])->prefix('requisitions')->name('requisitions.')->group(function () {
    Route::get('/', [RequisitionWebController::class, 'index'])->name('index');
    Route::get('/create', [RequisitionWebController::class, 'create'])->name('create');
    Route::post('/', [RequisitionWebController::class, 'store'])->name('store');
    // Ítems desde Excel (FrmEXCEL): solo lee y devuelve las líneas al formulario
    Route::post('/items/parse', [RequisitionWebController::class, 'parseItems'])->name('items.parse')->middleware('throttle:20,1');
    Route::get('/items/template', [RequisitionWebController::class, 'itemsTemplate'])->name('items.template');
    Route::put('/company-code', [RequisitionWebController::class, 'updateCompanyCode'])->name('company-code')
        ->middleware('can:company.document_code');
    Route::get('/{id}', [RequisitionWebController::class, 'show'])->name('show')->whereNumber('id');
    Route::get('/{id}/edit', [RequisitionWebController::class, 'edit'])->name('edit')->whereNumber('id');
    Route::put('/{id}', [RequisitionWebController::class, 'update'])->name('update')->whereNumber('id');
    Route::post('/{id}/cancel', [RequisitionWebController::class, 'cancel'])->name('cancel')->whereNumber('id');
    // Traza documental (archivos privados, los ve quien ve el requerimiento)
    Route::get('/{id}/order.pdf', [RequisitionWebController::class, 'orderPdf'])->name('order.pdf')->whereNumber('id');
    Route::get('/{id}/quotations/{quotation}/file', [RequisitionWebController::class, 'quotationFile'])->name('quotations.file')->whereNumber(['id', 'quotation']);
    Route::get('/{id}/invoice/file', [RequisitionWebController::class, 'invoiceFile'])->name('invoice.file')->whereNumber('id');
    Route::post('/{id}/invoice', [RequisitionWebController::class, 'invoice'])->name('invoice')->whereNumber('id'); // logística o tesorería

    // Gerencia aprueba; el Express lo valida la jefatura inmediata o contabilidad (regla en el controlador)
    Route::post('/{id}/review', [RequisitionWebController::class, 'review'])->name('review')->whereNumber('id');

    Route::middleware('can:purchasing.manage')->group(function () {
        Route::post('/{id}/quotations', [RequisitionWebController::class, 'storeQuotation'])->name('quotations.store')->whereNumber('id');
        Route::delete('/{id}/quotations/{quotation}', [RequisitionWebController::class, 'destroyQuotation'])->name('quotations.destroy')
            ->whereNumber(['id', 'quotation']);
        Route::post('/{id}/order', [RequisitionWebController::class, 'order'])->name('order')->whereNumber('id');
        Route::post('/{id}/quote-requests', [RequisitionWebController::class, 'sendQuoteRequests'])->name('quote-requests.store')->whereNumber('id')->middleware('throttle:20,1');
        Route::get('/{id}/quote-requests/{quoteRequest}/pdf', [RequisitionWebController::class, 'quoteRequestPdf'])->name('quote-requests.pdf')->whereNumber(['id', 'quoteRequest']);
    });
});

// Tesorería: programar y pagar (treasury.pay), autorizar (jefatura: requisitions.approve)
Route::middleware(['auth', 'web.tenant', 'can:treasury.view'])->prefix('treasury')->name('treasury.')->group(function () {
    Route::get('/', [TreasuryWebController::class, 'index'])->name('index');
    Route::get('/export', [TreasuryWebController::class, 'export'])->name('export')->middleware('throttle:20,1');
    Route::get('/payments/{id}/voucher', [TreasuryWebController::class, 'voucher'])->name('voucher')->whereNumber('id');

    // Autoriza la persona designada (apoderado con "autoriza pagos"): regla en PaymentInstallment::authorizeBy
    Route::post('/installments/authorize', [TreasuryWebController::class, 'authorizeMany'])->name('authorize-many');
    Route::post('/installments/{id}/authorize', [TreasuryWebController::class, 'authorizePayment'])->name('authorize')->whereNumber('id');
    Route::get('/installments/{id}/op.pdf', [TreasuryWebController::class, 'paymentOrderPdf'])->name('op.pdf')->whereNumber('id');

    Route::middleware('can:treasury.pay')->group(function () {
        Route::post('/installments/{id}/schedule', [TreasuryWebController::class, 'schedule'])->name('schedule')->whereNumber('id');
        Route::post('/installments/{id}/pay', [TreasuryWebController::class, 'pay'])->name('pay')->whereNumber('id');
        Route::post('/requisitions/{id}/detraction', [TreasuryWebController::class, 'detraction'])->name('detraction')->whereNumber('id');
        Route::post('/direct', [TreasuryWebController::class, 'directPayment'])->name('direct');
        Route::put('/direct/{id}', [TreasuryWebController::class, 'updateDirectPayment'])->name('direct.update')->whereNumber('id');
        Route::post('/payments/{id}/correct', [TreasuryWebController::class, 'correctPayment'])->name('payments.correct')->whereNumber('id'); // POST: lleva archivo
        Route::post('/accounts', [TreasuryWebController::class, 'storeAccount'])->name('accounts.store');
        Route::put('/accounts/{id}', [TreasuryWebController::class, 'updateAccount'])->name('accounts.update')->whereNumber('id');
    });
});

// Caja chica
Route::middleware(['auth', 'web.tenant', 'can:fixed_costs.manage'])->prefix('petty-cash')->name('petty-cash.')->group(function () {
    Route::get('/', [PettyCashWebController::class, 'index'])->name('index');
    Route::post('/', [PettyCashWebController::class, 'store'])->name('store');
    Route::post('/import', [PettyCashWebController::class, 'import'])->name('import')->middleware('throttle:10,1');
    Route::get('/template', [PettyCashWebController::class, 'template'])->name('template');
    Route::post('/{id}', [PettyCashWebController::class, 'update'])->name('update')->whereNumber('id'); // POST: lleva archivo
    Route::delete('/{id}', [PettyCashWebController::class, 'destroy'])->name('destroy')->whereNumber('id');
    Route::get('/{id}/voucher', [PettyCashWebController::class, 'voucher'])->name('voucher')->whereNumber('id');
});

// Costos fijos: cuotas mensuales por lote (arrendamientos, leasing, seguros...)
Route::middleware(['auth', 'web.tenant', 'can:fixed_costs.manage'])->prefix('fixed-costs')->name('fixed-costs.')->group(function () {
    Route::get('/', [FixedCostWebController::class, 'index'])->name('index');
    Route::post('/', [FixedCostWebController::class, 'store'])->name('store');
    Route::put('/{id}', [FixedCostWebController::class, 'update'])->name('update')->whereNumber('id');
    Route::post('/{id}/cancel', [FixedCostWebController::class, 'cancel'])->name('cancel')->whereNumber('id');
    Route::post('/{id}/pay', [FixedCostWebController::class, 'pay'])->name('pay')->whereNumber('id');
});

// Maestros: vehículos de la empresa
Route::middleware(['auth', 'web.tenant', 'can:fleet.manage'])->prefix('vehicles')->name('vehicles.')->group(function () {
    Route::get('/', [VehicleWebController::class, 'index'])->name('index');
    Route::post('/', [VehicleWebController::class, 'store'])->name('store');
    Route::put('/{id}', [VehicleWebController::class, 'update'])->name('update')->whereNumber('id');
    Route::delete('/{id}', [VehicleWebController::class, 'destroy'])->name('destroy')->whereNumber('id');
});

// Maestros: apoderados con firma y quién autoriza pagos
Route::middleware(['auth', 'web.tenant', 'can:signatories.manage'])->prefix('signatories')->name('signatories.')->group(function () {
    Route::get('/', [SignatoryWebController::class, 'index'])->name('index');
    Route::post('/', [SignatoryWebController::class, 'store'])->name('store');
    Route::post('/{id}', [SignatoryWebController::class, 'update'])->name('update')->whereNumber('id'); // POST: lleva la firma
    Route::get('/{id}/signature', [SignatoryWebController::class, 'signature'])->name('signature')->whereNumber('id');
});

// Logística: almacén (stock, kardex, recepción de OC) y combustible por placa
Route::middleware(['auth', 'web.tenant', 'can:inventory.manage'])->prefix('inventory')->name('inventory.')->group(function () {
    Route::get('/', [InventoryWebController::class, 'index'])->name('index');
    Route::post('/movements', [InventoryWebController::class, 'store'])->name('movements.store');
    Route::post('/receive/{id}', [InventoryWebController::class, 'receive'])->name('receive')->whereNumber('id');
});
Route::middleware(['auth', 'web.tenant', 'can:fuel.manage'])->prefix('fuel')->name('fuel.')->group(function () {
    Route::get('/', [FuelWebController::class, 'index'])->name('index');
    Route::post('/', [FuelWebController::class, 'store'])->name('store');
    Route::put('/{id}', [FuelWebController::class, 'update'])->name('update')->whereNumber('id');
    Route::delete('/{id}', [FuelWebController::class, 'destroy'])->name('destroy')->whereNumber('id');
});

// Pagos de RRHH: la planilla importada pasa a Tesorería (FrmIplanilla + FrmRegrrhh)
Route::middleware(['auth', 'web.tenant', 'can:payroll.payments'])->prefix('payroll-payments')->name('payroll-payments.')->group(function () {
    Route::get('/', [PayrollPaymentWebController::class, 'index'])->name('index');
    Route::post('/import', [PayrollPaymentWebController::class, 'import'])->name('import')->middleware('throttle:10,1');
    Route::get('/template', [PayrollPaymentWebController::class, 'template'])->name('template');
});

// Maestros: catálogo de productos y servicios (Modificar_producto / eliminar_producto)
Route::middleware(['auth', 'web.tenant', 'can:purchasing.manage'])->prefix('products')->name('products.')->group(function () {
    Route::get('/', [\App\Modules\Finance\Controllers\Web\ProductWebController::class, 'index'])->name('index');
    Route::put('/{id}', [\App\Modules\Finance\Controllers\Web\ProductWebController::class, 'update'])->name('update')->whereNumber('id');
    Route::delete('/{id}', [\App\Modules\Finance\Controllers\Web\ProductWebController::class, 'destroy'])->name('destroy')->whereNumber('id');
});

// Consulta de compras por producto (FrmConsultar)
Route::middleware(['auth', 'web.tenant', 'can:suppliers.view'])->get('/purchases/by-product', [InventoryWebController::class, 'purchases'])->name('purchases.by-product');

// Reportes en Excel (Crystal Reports de sisgere); cada reporte valida su permiso
Route::middleware(['auth', 'web.tenant'])->prefix('finance-reports')->name('finance-reports.')->group(function () {
    Route::get('/', [\App\Modules\Finance\Controllers\Web\ReportWebController::class, 'index'])->name('index');
    Route::get('/{report}', [\App\Modules\Finance\Controllers\Web\ReportWebController::class, 'download'])->name('download')
        ->where('report', '[a-z_]+')->middleware('throttle:30,1');
});

// Flujo de caja: egresos reales y proyectados por mes
Route::middleware(['auth', 'web.tenant', 'can:treasury.view'])->get('/cash-flow', [CashFlowWebController::class, 'index'])->name('cash-flow.index');
