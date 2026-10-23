<?php

use App\Modules\Finance\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

// Finance (API): compras y tesorería migradas del ERP de escritorio.
// Convenciones de middleware en Core/Routes/api.php.
Route::middleware(['api.tenant', 'permission:suppliers.view'])->prefix('suppliers')->group(function () {
    Route::get('/', [SupplierController::class, 'index']);
});
