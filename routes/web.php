<?php

use App\Http\Controllers\SecureFileController;
use App\Http\Controllers\Web\ApprovalWebController;
use App\Http\Controllers\Web\CompanySettingsWebController;
use App\Http\Controllers\Web\DocumentWebController;
use App\Http\Controllers\Web\PayrollWebController;
use App\Http\Controllers\Web\UniformWebController;
use App\Http\Controllers\Web\AttendanceWebController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\BranchWebController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\EmployeeController;
use App\Http\Controllers\Web\PayslipWebController;
use App\Http\Controllers\Web\ReportWebController;
use App\Http\Controllers\Web\ShiftWebController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes — Panel administrativo y de RRHH
|--------------------------------------------------------------------------
| [auth, web.tenant]  → sesión + TenantContext de la empresa del usuario
|                       (activa los global scopes igual que en la API)
| can:<permiso>       → matriz de RolesAndPermissionsSeeder (Gate::before)
*/

// Redirección inicial
Route::get('/', function () {
    return redirect()->route('dashboard');
});

// Archivos privados (legajo, selfies) con URL firmada y temporal
Route::get('/secure-files/{kind}/{id}', [SecureFileController::class, 'show'])
    ->name('secure-files.show')
    ->whereIn('kind', \App\Services\SecureFileUrl::KINDS)
    ->whereNumber('id')
    ->middleware(['signed', 'throttle:120,1']);

// Rutas públicas de autenticación
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.post')->middleware('throttle:10,1');
});

Route::middleware(['auth', 'web.tenant'])->group(function () {
    // Logout
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Dashboard en tiempo real
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/live-stream', [DashboardController::class, 'liveStream'])->name('dashboard.live-stream');
    Route::post('/admin/switch-tenant', [DashboardController::class, 'switchTenant'])->name('admin.tenant.switch');

    // Gestión de Colaboradores
    Route::prefix('employees')->name('employees.')->middleware('can:users.manage')->group(function () {
        Route::get('/', [EmployeeController::class, 'index'])->name('index');
        Route::get('/create', [EmployeeController::class, 'create'])->name('create');
        Route::post('/', [EmployeeController::class, 'store'])->name('store');
        Route::get('/{id}/edit', [EmployeeController::class, 'edit'])->name('edit');
        Route::put('/{id}', [EmployeeController::class, 'update'])->name('update');
        Route::patch('/{id}/toggle-status', [EmployeeController::class, 'toggleStatus'])->name('toggle-status');
        Route::patch('/{id}/unlock', [EmployeeController::class, 'unlock'])->name('unlock');
    });

    // Legajo digital: ficha del colaborador y revisión de documentos
    Route::prefix('documents')->name('documents.')->middleware('can:documents.review')->group(function () {
        Route::get('/', [DocumentWebController::class, 'index'])->name('index');
        Route::post('/{id}/review', [DocumentWebController::class, 'review'])->name('review')->whereNumber('id');
        Route::get('/{id}/open', [DocumentWebController::class, 'open'])->name('open')->whereNumber('id');
    });
    Route::prefix('employees/{id}/file')->name('employees.file')->whereNumber('id')->middleware('can:profiles.manage')->group(function () {
        Route::get('/', [DocumentWebController::class, 'employeeFile'])->name('');
        Route::put('/profile', [DocumentWebController::class, 'updateProfile'])->name('.profile');
        Route::post('/payment-review', [DocumentWebController::class, 'reviewPayment'])->name('.payment-review');
        Route::post('/reveal', [DocumentWebController::class, 'reveal'])->name('.reveal')->middleware('can:profiles.view_sensitive');
        Route::post('/documents', [DocumentWebController::class, 'upload'])->name('.upload')->middleware('can:documents.review');
    });

    // Dotación de uniformes
    Route::prefix('uniforms')->name('uniforms.')->middleware('can:uniforms.manage')->group(function () {
        Route::get('/', [UniformWebController::class, 'index'])->name('index');
        Route::get('/export', [UniformWebController::class, 'export'])->name('export');
        Route::post('/deliveries', [UniformWebController::class, 'storeDelivery'])->name('deliveries.store');
    });

    // Cruce con planilla / nómina
    Route::prefix('payroll')->name('payroll.')->middleware('can:payroll.export')->group(function () {
        Route::get('/', [PayrollWebController::class, 'index'])->name('index');
        Route::get('/export', [PayrollWebController::class, 'export'])->name('export');
    });

    // Panel de control de visibilidad de la app móvil
    Route::prefix('settings/mobile')->name('settings.mobile')->middleware('can:company.configure')->group(function () {
        Route::get('/', [CompanySettingsWebController::class, 'edit']);
        Route::put('/', [CompanySettingsWebController::class, 'update'])->name('.update');
    });

    // Turnos (fijos y rotativos), tolerancias y asignaciones
    Route::prefix('shifts')->name('shifts.')->middleware('can:shifts.manage')->group(function () {
        Route::get('/', [ShiftWebController::class, 'index'])->name('index');
        Route::get('/create', [ShiftWebController::class, 'create'])->name('create');
        Route::post('/', [ShiftWebController::class, 'store'])->name('store');
        Route::get('/{id}/edit', [ShiftWebController::class, 'edit'])->name('edit');
        Route::put('/{id}', [ShiftWebController::class, 'update'])->name('update');
        Route::delete('/{id}', [ShiftWebController::class, 'destroy'])->name('destroy');
        Route::post('/assign', [ShiftWebController::class, 'assign'])->name('assign')->middleware('can:shifts.assign');
        Route::delete('/assignments/{id}', [ShiftWebController::class, 'unassign'])->name('unassign')->middleware('can:shifts.assign');
    });

    // Control de Asistencias, faltas y auditoría GPS / Selfie
    Route::prefix('attendance')->name('attendance.')->middleware('can:attendance.view_all')->group(function () {
        Route::get('/', [AttendanceWebController::class, 'index'])->name('index');
        Route::get('/{id}', [AttendanceWebController::class, 'show'])->name('show')->whereNumber('id');
        Route::get('/{id}/evidence', [AttendanceWebController::class, 'evidence'])->name('evidence')->whereNumber('id');
        Route::post('/{id}/review', [AttendanceWebController::class, 'reviewJustification'])->name('review')->middleware('can:attendance.review');
        Route::get('/{id}/justification-file', [AttendanceWebController::class, 'justificationFile'])->name('justification-file');
    });

    // Aprobaciones: vacaciones, permisos, licencias y justificaciones
    Route::prefix('approvals')->name('approvals.')->middleware('can:leave.approve_team')->group(function () {
        Route::get('/', [ApprovalWebController::class, 'index'])->name('index');
        Route::post('/leave/{id}', [ApprovalWebController::class, 'reviewLeave'])->name('leave.review');
        Route::get('/leave/{id}/attachment', [ApprovalWebController::class, 'leaveAttachment'])->name('leave.attachment');
    });

    // Gestión de Sucursales y Geocercas (GPS Leaflet)
    Route::prefix('branches')->name('branches.')->middleware('can:branches.manage')->group(function () {
        Route::get('/', [BranchWebController::class, 'index'])->name('index');
        Route::get('/create', [BranchWebController::class, 'create'])->name('create');
        Route::post('/', [BranchWebController::class, 'store'])->name('store');
        Route::get('/{id}/edit', [BranchWebController::class, 'edit'])->name('edit');
        Route::put('/{id}', [BranchWebController::class, 'update'])->name('update');
        Route::delete('/{id}', [BranchWebController::class, 'destroy'])->name('destroy');
    });

    // Módulo de Nómina y Boletas de Pago en PDF
    Route::prefix('payslips')->name('payslips.')->middleware('can:payslips.manage')->group(function () {
        Route::get('/', [PayslipWebController::class, 'index'])->name('index');
        Route::get('/create', [PayslipWebController::class, 'create'])->name('create');
        Route::post('/', [PayslipWebController::class, 'store'])->name('store');
        Route::get('/bulk', [PayslipWebController::class, 'bulkCreate'])->name('bulk');
        Route::post('/bulk', [PayslipWebController::class, 'bulkStore'])->name('bulk.store');
        Route::post('/{id}/publish', [PayslipWebController::class, 'publish'])->name('publish');
        Route::post('/bulk-publish', [PayslipWebController::class, 'bulkPublish'])->name('bulk-publish');
        Route::get('/{id}/download', [PayslipWebController::class, 'download'])->name('download');
        Route::get('/{id}/view', [PayslipWebController::class, 'view'])->name('view');
    });

    // Reportes analíticos y exportación
    Route::prefix('reports')->name('reports.')->middleware('can:reports.view')->group(function () {
        Route::get('/', [ReportWebController::class, 'index'])->name('index');
        Route::get('/export/excel', [ReportWebController::class, 'exportExcel'])->name('export.excel')->middleware('can:reports.export');
        Route::get('/export/pdf', [ReportWebController::class, 'exportPdf'])->name('export.pdf')->middleware('can:reports.export');
    });
});
