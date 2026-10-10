<?php

use App\Modules\HR\Controllers\Api\Admin\DocumentReviewController;
use App\Modules\HR\Controllers\Api\Admin\EmployeeProfileController;
use App\Modules\HR\Controllers\Api\Admin\UniformAdminController;
use App\Modules\HR\Controllers\Api\Employee\DocumentController;
use App\Modules\HR\Controllers\Api\Employee\ProfileController;
use App\Modules\HR\Controllers\Api\Employee\UniformController;
use App\Modules\HR\Controllers\Api\LeaveRequestController;
use App\Modules\HR\Controllers\Api\ShiftController;
use App\Modules\HR\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// HR (API): colaboradores, turnos, legajo, dotación y solicitudes.
// Convenciones de middleware en Core/Routes/api.php.
Route::middleware('api.tenant')->group(function () {
    Route::middleware('permission:shifts.view_own')->group(function () {
        Route::get('my-shifts', [ShiftController::class, 'myShifts']);
        Route::get('schedule',  [ShiftController::class, 'schedule']);  // Vista de calendario
    });

    // Legajo del propio colaborador
    Route::middleware('permission:profile.manage_own')->prefix('employee')->group(function () {
        Route::get('config',       [ProfileController::class, 'config']);
        Route::get('profile',      [ProfileController::class, 'show']);
        Route::put('payment-info', [ProfileController::class, 'updatePaymentInfo']);
        Route::put('contact',      [ProfileController::class, 'updateContact']);
        Route::get('documents',          [DocumentController::class, 'index']);
        Route::post('documents',         [DocumentController::class, 'store'])->middleware('throttle:20,1');
        Route::get('documents/{id}/url', [DocumentController::class, 'url'])->whereNumber('id');
        Route::delete('documents/{id}',  [DocumentController::class, 'destroy'])->whereNumber('id');
        Route::get('uniform-sizes', [UniformController::class, 'show']);
        Route::put('uniform-sizes', [UniformController::class, 'update']);
    });

    Route::middleware('permission:leave.request')->prefix('leave-requests')->group(function () {
        Route::get('/',            [LeaveRequestController::class, 'index']);
        Route::post('/',           [LeaveRequestController::class, 'store']);
        Route::get('{id}',         [LeaveRequestController::class, 'show'])->whereNumber('id');
        Route::post('{id}/cancel', [LeaveRequestController::class, 'cancel'])->whereNumber('id');
    });
    Route::middleware('permission:leave.approve_team,leave.approve_all')->prefix('approvals/leave-requests')->group(function () {
        Route::get('/',             [LeaveRequestController::class, 'pendingApprovals']);
        Route::post('{id}/approve', [LeaveRequestController::class, 'approve'])->whereNumber('id');
        Route::post('{id}/reject',  [LeaveRequestController::class, 'reject'])->whereNumber('id');
    });

    Route::prefix('admin')->group(function () {
        Route::middleware('permission:users.manage')->group(function () {
            Route::apiResource('employees', UserController::class);
            Route::post('employees/{id}/assign-branch', [UserController::class, 'assignBranch']);
        });
        Route::post('employees/{id}/assign-shift', [UserController::class, 'assignShift'])
            ->middleware('permission:shifts.assign');
        Route::apiResource('shifts', ShiftController::class)->middleware('permission:shifts.manage');

        Route::middleware('permission:documents.review')->group(function () {
            Route::get('documents',                 [DocumentReviewController::class, 'index']);
            Route::get('documents/{id}/url',        [DocumentReviewController::class, 'url'])->whereNumber('id');
            Route::post('documents/{id}/approve',   [DocumentReviewController::class, 'approve'])->whereNumber('id');
            Route::post('documents/{id}/reject',    [DocumentReviewController::class, 'reject'])->whereNumber('id');
            Route::post('employees/{id}/documents', [DocumentReviewController::class, 'storeFor'])->whereNumber('id');
        });
        Route::middleware('permission:profiles.manage')->group(function () {
            Route::get('employees/{id}/profile',              [EmployeeProfileController::class, 'show'])->whereNumber('id');
            Route::put('employees/{id}/profile',              [EmployeeProfileController::class, 'update'])->whereNumber('id');
            Route::post('employees/{id}/payment-info/review', [EmployeeProfileController::class, 'reviewPayment'])->whereNumber('id');
        });
        Route::middleware('permission:uniforms.manage')->group(function () {
            Route::get('uniforms',                     [UniformAdminController::class, 'index']);
            Route::get('uniforms/export',              [UniformAdminController::class, 'export']);
            Route::post('uniforms/deliveries',         [UniformAdminController::class, 'storeDelivery']);
            Route::put('employees/{id}/uniform-sizes', [UniformAdminController::class, 'updateSizes'])->whereNumber('id');
        });
    });

    Route::get('supervisor/team', [UserController::class, 'myTeam'])->middleware('permission:attendance.view_team');
});
