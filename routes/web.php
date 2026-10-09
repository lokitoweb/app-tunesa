<?php

use App\Http\Controllers\Web\AttendanceWebController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\BranchWebController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\EmployeeController;
use App\Http\Controllers\Web\PayslipWebController;
use App\Http\Controllers\Web\ReportWebController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes — TuneSA Multi-Tenant Web Management
|--------------------------------------------------------------------------
*/

// Redirección inicial
Route::get('/', function () {
    return redirect()->route('dashboard');
});

// Rutas públicas de autenticación
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.post');
});

// Rutas protegidas para Administradores, RRHH y Supervisores
Route::middleware(['auth'])->group(function () {
    // Logout
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Dashboard en tiempo real
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/live-stream', [DashboardController::class, 'liveStream'])->name('dashboard.live-stream');

    // Gestión de Colaboradores
    Route::prefix('employees')->name('employees.')->group(function () {
        Route::get('/', [EmployeeController::class, 'index'])->name('index');
        Route::get('/create', [EmployeeController::class, 'create'])->name('create');
        Route::post('/', [EmployeeController::class, 'store'])->name('store');
        Route::get('/{id}/edit', [EmployeeController::class, 'edit'])->name('edit');
        Route::put('/{id}', [EmployeeController::class, 'update'])->name('update');
        Route::patch('/{id}/toggle-status', [EmployeeController::class, 'toggleStatus'])->name('toggle-status');
    });

    // Control de Asistencias y Auditoría GPS / Selfie
    Route::prefix('attendance')->name('attendance.')->group(function () {
        Route::get('/', [AttendanceWebController::class, 'index'])->name('index');
        Route::get('/{id}', [AttendanceWebController::class, 'show'])->name('show');
        Route::post('/{id}/review', [AttendanceWebController::class, 'reviewJustification'])->name('review');
    });

    // Gestión de Sucursales y Geocercas (GPS Leaflet)
    Route::prefix('branches')->name('branches.')->group(function () {
        Route::get('/', [BranchWebController::class, 'index'])->name('index');
        Route::get('/create', [BranchWebController::class, 'create'])->name('create');
        Route::post('/', [BranchWebController::class, 'store'])->name('store');
        Route::get('/{id}/edit', [BranchWebController::class, 'edit'])->name('edit');
        Route::put('/{id}', [BranchWebController::class, 'update'])->name('update');
        Route::delete('/{id}', [BranchWebController::class, 'destroy'])->name('destroy');
    });

    // Módulo de Nómina y Boletas de Pago en PDF
    Route::prefix('payslips')->name('payslips.')->group(function () {
        Route::get('/', [PayslipWebController::class, 'index'])->name('index');
        Route::get('/create', [PayslipWebController::class, 'create'])->name('create');
        Route::post('/', [PayslipWebController::class, 'store'])->name('store');
        Route::post('/{id}/publish', [PayslipWebController::class, 'publish'])->name('publish');
        Route::post('/bulk-publish', [PayslipWebController::class, 'bulkPublish'])->name('bulk-publish');
        Route::get('/{id}/download', [PayslipWebController::class, 'download'])->name('download');
    });

    // Reportes analíticos y exportación
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportWebController::class, 'index'])->name('index');
        Route::get('/export/excel', [ReportWebController::class, 'exportExcel'])->name('export.excel');
        Route::get('/export/pdf', [ReportWebController::class, 'exportPdf'])->name('export.pdf');
    });
});
