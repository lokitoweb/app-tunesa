<?php

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
    Route::get('/{id}', [RequisitionWebController::class, 'show'])->name('show')->whereNumber('id');
    Route::get('/{id}/edit', [RequisitionWebController::class, 'edit'])->name('edit')->whereNumber('id');
    Route::put('/{id}', [RequisitionWebController::class, 'update'])->name('update')->whereNumber('id');
    Route::post('/{id}/cancel', [RequisitionWebController::class, 'cancel'])->name('cancel')->whereNumber('id');

    Route::post('/{id}/review', [RequisitionWebController::class, 'review'])->name('review')->whereNumber('id')
        ->middleware('can:requisitions.approve');

    Route::middleware('can:purchasing.manage')->group(function () {
        Route::post('/{id}/quotations', [RequisitionWebController::class, 'storeQuotation'])->name('quotations.store')->whereNumber('id');
        Route::delete('/{id}/quotations/{quotation}', [RequisitionWebController::class, 'destroyQuotation'])->name('quotations.destroy')
            ->whereNumber(['id', 'quotation']);
        Route::post('/{id}/order', [RequisitionWebController::class, 'order'])->name('order')->whereNumber('id');
    });
});

// Tesorería: programar y pagar (treasury.pay), autorizar (jefatura: requisitions.approve)
Route::middleware(['auth', 'web.tenant', 'can:treasury.view'])->prefix('treasury')->name('treasury.')->group(function () {
    Route::get('/', [TreasuryWebController::class, 'index'])->name('index');
    Route::get('/payments/{id}/voucher', [TreasuryWebController::class, 'voucher'])->name('voucher')->whereNumber('id');

    Route::post('/installments/{id}/authorize', [TreasuryWebController::class, 'authorizePayment'])->name('authorize')->whereNumber('id')
        ->middleware('can:requisitions.approve');

    Route::middleware('can:treasury.pay')->group(function () {
        Route::post('/installments/{id}/schedule', [TreasuryWebController::class, 'schedule'])->name('schedule')->whereNumber('id');
        Route::post('/installments/{id}/pay', [TreasuryWebController::class, 'pay'])->name('pay')->whereNumber('id');
        Route::post('/requisitions/{id}/detraction', [TreasuryWebController::class, 'detraction'])->name('detraction')->whereNumber('id');
        Route::post('/accounts', [TreasuryWebController::class, 'storeAccount'])->name('accounts.store');
        Route::put('/accounts/{id}', [TreasuryWebController::class, 'updateAccount'])->name('accounts.update')->whereNumber('id');
    });
});

// Caja chica
Route::middleware(['auth', 'web.tenant', 'can:fixed_costs.manage'])->prefix('petty-cash')->name('petty-cash.')->group(function () {
    Route::get('/', [PettyCashWebController::class, 'index'])->name('index');
    Route::post('/', [PettyCashWebController::class, 'store'])->name('store');
    Route::post('/{id}', [PettyCashWebController::class, 'update'])->name('update')->whereNumber('id'); // POST: lleva archivo
    Route::delete('/{id}', [PettyCashWebController::class, 'destroy'])->name('destroy')->whereNumber('id');
    Route::get('/{id}/voucher', [PettyCashWebController::class, 'voucher'])->name('voucher')->whereNumber('id');
});
