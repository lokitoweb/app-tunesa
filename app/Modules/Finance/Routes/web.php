<?php

use App\Modules\Finance\Controllers\Web\RequisitionWebController;
use App\Modules\Finance\Controllers\Web\SupplierWebController;
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
