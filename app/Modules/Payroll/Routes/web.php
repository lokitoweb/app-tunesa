<?php

use App\Modules\Payroll\Controllers\Web\PayrollWebController;
use App\Modules\Payroll\Controllers\Web\PayslipWebController;
use Illuminate\Support\Facades\Route;

// Payroll (panel web): cruce con planilla y boletas de pago
Route::middleware(['auth', 'web.tenant'])->group(function () {
    // Cruce con planilla / nómina
    Route::prefix('payroll')->name('payroll.')->middleware('can:payroll.export')->group(function () {
        Route::get('/', [PayrollWebController::class, 'index'])->name('index');
        Route::get('/export', [PayrollWebController::class, 'export'])->name('export');
    });

    // Boletas de pago en PDF
    Route::prefix('payslips')->name('payslips.')->middleware('can:payslips.manage')->group(function () {
        Route::get('/', [PayslipWebController::class, 'index'])->name('index');
        Route::get('/create', [PayslipWebController::class, 'create'])->name('create');
        Route::post('/', [PayslipWebController::class, 'store'])->name('store');
        Route::get('/bulk', [PayslipWebController::class, 'bulkCreate'])->name('bulk');
        Route::post('/bulk', [PayslipWebController::class, 'bulkStore'])->name('bulk.store');
        Route::post('/{id}/publish', [PayslipWebController::class, 'publish'])->name('publish')->whereNumber('id');
        Route::post('/{id}/annul', [PayslipWebController::class, 'annul'])->name('annul')->whereNumber('id');
        Route::delete('/{id}', [PayslipWebController::class, 'destroy'])->name('destroy')->whereNumber('id');
        Route::post('/bulk-publish', [PayslipWebController::class, 'bulkPublish'])->name('bulk-publish');
        Route::get('/{id}/download', [PayslipWebController::class, 'download'])->name('download')->whereNumber('id');
        Route::get('/{id}/view', [PayslipWebController::class, 'view'])->name('view')->whereNumber('id');
    });
});
