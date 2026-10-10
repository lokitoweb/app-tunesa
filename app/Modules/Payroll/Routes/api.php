<?php

use App\Modules\Payroll\Controllers\Api\Admin\PayrollController;
use App\Modules\Payroll\Controllers\Api\PayslipController;
use Illuminate\Support\Facades\Route;

// Payroll (API): boletas de pago y exportación para planilla.
// Convenciones de middleware en Core/Routes/api.php.
Route::middleware('api.tenant')->group(function () {
    Route::middleware('permission:payslips.view_own,payslips.manage')->group(function () {
        Route::get('payslips',               [PayslipController::class, 'index']);
        Route::get('payslips/{id}',          [PayslipController::class, 'show'])->whereNumber('id');
        Route::get('payslips/{id}/download', [PayslipController::class, 'download'])->whereNumber('id');
        Route::post('payslips/{id}/read',    [PayslipController::class, 'markAsRead'])->whereNumber('id');
    });

    Route::prefix('admin')->group(function () {
        Route::middleware('permission:payslips.manage')->group(function () {
            Route::post('payslips/bulk', [PayslipController::class, 'bulkStore']);
            Route::apiResource('payslips', PayslipController::class)->except(['index', 'show']);
            Route::post('payslips/{id}/publish',     [PayslipController::class, 'publish']);
            Route::get('payslips/employee/{userId}', [PayslipController::class, 'byEmployee']);
        });
        Route::get('payroll/export', [PayrollController::class, 'export'])
            ->middleware(['permission:payroll.export', 'throttle:10,1']);
    });
});
