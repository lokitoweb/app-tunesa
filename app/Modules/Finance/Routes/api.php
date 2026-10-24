<?php

use App\Modules\Finance\Controllers\Api\PaymentAuthorizationController;
use App\Modules\Finance\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

// Finance (API): compras y tesorería migradas del ERP de escritorio.
// Convenciones de middleware en Core/Routes/api.php.
Route::middleware(['api.tenant', 'permission:suppliers.view'])->prefix('suppliers')->group(function () {
    Route::get('/', [SupplierController::class, 'index']);
});

// Firma APP: la persona designada autoriza las órdenes de pago (regla en PaymentInstallment::authorizeBy)
Route::middleware(['api.tenant'])->prefix('treasury/authorizations')->group(function () {
    Route::get('/', [PaymentAuthorizationController::class, 'index']);
    Route::post('{id}/authorize', [PaymentAuthorizationController::class, 'approve'])->whereNumber('id')->middleware('throttle:30,1');
});
