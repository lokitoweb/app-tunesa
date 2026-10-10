<?php

use App\Modules\Attendance\Controllers\Api\Admin\AttendanceDetailController;
use App\Modules\Attendance\Controllers\Api\AttendanceController;
use App\Modules\Attendance\Controllers\Api\AttendanceSyncController;
use Illuminate\Support\Facades\Route;

// Attendance (API): marcación, sincronización offline, tableros y reportes.
// Convenciones de middleware en Core/Routes/api.php.
Route::middleware('api.tenant')->group(function () {
    // Sincronización en lote de marcaciones hechas sin conexión
    Route::middleware(['permission:attendance.mark', 'throttle:30,1'])->group(function () {
        Route::post('v1/attendances/sync-batch', [AttendanceSyncController::class, 'syncBatch']);
        Route::post('attendances/sync-batch',    [AttendanceSyncController::class, 'syncBatch']);
    });

    Route::prefix('attendance')->group(function () {
        Route::middleware('permission:attendance.view_own')->group(function () {
            Route::get('today',   [AttendanceController::class, 'today']);
            Route::get('history', [AttendanceController::class, 'history']);
            Route::post('{id}/justification', [AttendanceController::class, 'submitJustification'])->whereNumber('id');
        });
        Route::middleware('permission:attendance.mark')->group(function () {
            Route::post('clock-in',    [AttendanceController::class, 'clockIn']);
            Route::post('break-start', [AttendanceController::class, 'breakStart']);
            Route::post('break-end',   [AttendanceController::class, 'breakEnd']);
            Route::post('clock-out',   [AttendanceController::class, 'clockOut']);
        });
    });

    Route::prefix('admin')->group(function () {
        Route::middleware('permission:attendance.view_all')->group(function () {
            Route::get('dashboard/live',  [AttendanceController::class, 'liveDashboard']);
            Route::get('dashboard/stats', [AttendanceController::class, 'dashboardStats']);
            Route::get('attendance/{id}', [AttendanceDetailController::class, 'show'])->whereNumber('id');
        });
        Route::get('reports/attendance', [AttendanceController::class, 'report'])
            ->middleware('permission:reports.view');
        Route::get('reports/attendance/export', [AttendanceController::class, 'exportReport'])
            ->middleware('permission:reports.export');
        Route::middleware('permission:attendance.review')->group(function () {
            Route::post('attendance/{id}/approve-justification', [AttendanceController::class, 'approveJustification']);
            Route::post('attendance/{id}/reject-justification',  [AttendanceController::class, 'rejectJustification']);
        });
    });

    Route::middleware('permission:attendance.view_team')->prefix('supervisor')->group(function () {
        Route::get('attendance/live', [AttendanceController::class, 'teamLive']);
        Route::get('attendance',      [AttendanceController::class, 'teamReport']);
    });
});
