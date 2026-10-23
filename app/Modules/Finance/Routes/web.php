<?php

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
