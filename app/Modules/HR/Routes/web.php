<?php

use App\Modules\HR\Controllers\Web\ApprovalWebController;
use App\Modules\HR\Controllers\Web\DocumentWebController;
use App\Modules\HR\Controllers\Web\EmployeeController;
use App\Modules\HR\Controllers\Web\HolidayWebController;
use App\Modules\HR\Controllers\Web\ScheduleWebController;
use App\Modules\HR\Controllers\Web\ShiftWebController;
use App\Modules\HR\Controllers\Web\UniformWebController;
use Illuminate\Support\Facades\Route;

// HR (panel web): colaboradores, legajos, dotación, turnos, feriados y aprobaciones
Route::middleware(['auth', 'web.tenant'])->group(function () {
    // Gestión de Colaboradores
    Route::prefix('employees')->name('employees.')->middleware('can:users.manage')->group(function () {
        Route::get('/', [EmployeeController::class, 'index'])->name('index');
        Route::get('/create', [EmployeeController::class, 'create'])->name('create');
        Route::post('/', [EmployeeController::class, 'store'])->name('store');
        // Planilla en Excel (FrmIplanilla): revisar y luego dar de alta solo a los nuevos
        Route::post('/import/preview', [\App\Modules\HR\Controllers\Web\EmployeeImportController::class, 'preview'])->name('import.preview')->middleware('throttle:20,1');
        Route::post('/import', [\App\Modules\HR\Controllers\Web\EmployeeImportController::class, 'store'])->name('import')->middleware('throttle:10,1');
        Route::get('/import/template', [\App\Modules\HR\Controllers\Web\EmployeeImportController::class, 'template'])->name('import.template');
        Route::get('/{id}/edit', [EmployeeController::class, 'edit'])->name('edit')->whereNumber('id');
        Route::put('/{id}', [EmployeeController::class, 'update'])->name('update')->whereNumber('id');
        Route::patch('/{id}/toggle-status', [EmployeeController::class, 'toggleStatus'])->name('toggle-status')->whereNumber('id');
        Route::patch('/{id}/unlock', [EmployeeController::class, 'unlock'])->name('unlock')->whereNumber('id');
        Route::get('/tenant-options/{id}', [EmployeeController::class, 'tenantOptions'])->name('tenant-options')->whereNumber('id');
        // Cargo nuevo desde el alta del colaborador (sin ir a Organización)
        Route::post('/positions', [EmployeeController::class, 'storePosition'])->name('positions.store');
        Route::post('/{id}/reset-access', [EmployeeController::class, 'resetAccess'])->name('reset-access')->whereNumber('id')->middleware('throttle:10,1');
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
        Route::match(['get', 'post'], '/reveal', [DocumentWebController::class, 'reveal'])->name('.reveal')->middleware('can:profiles.view_sensitive');
        Route::post('/documents', [DocumentWebController::class, 'upload'])->name('.upload')->middleware('can:documents.review');
    });

    // Regímenes laborales de la empresa (parámetros de vacaciones, gratificación, CTS e indemnización)
    Route::prefix('labor-regimes')->name('labor-regimes.')->middleware('can:labor_regimes.manage')->group(function () {
        Route::get('/', [\App\Modules\HR\Controllers\Web\LaborRegimeWebController::class, 'index'])->name('index');
        Route::post('/', [\App\Modules\HR\Controllers\Web\LaborRegimeWebController::class, 'store'])->name('store');
        Route::put('/{id}', [\App\Modules\HR\Controllers\Web\LaborRegimeWebController::class, 'update'])->name('update')->whereNumber('id');
    });

    // Cese y liquidación de beneficios sociales
    Route::middleware('can:settlements.manage')->group(function () {
        Route::get('/employees/{id}/settlement', [\App\Modules\HR\Controllers\Web\SettlementWebController::class, 'create'])->name('settlements.create')->whereNumber('id');
        Route::post('/employees/{id}/settlement', [\App\Modules\HR\Controllers\Web\SettlementWebController::class, 'store'])->name('settlements.store')->whereNumber('id');
        Route::get('/settlements/{id}/print', [\App\Modules\HR\Controllers\Web\SettlementWebController::class, 'print'])->name('settlements.print')->whereNumber('id');
    });

    // Dotación de uniformes
    // Vencimientos: legajo, contratos, capacitaciones y vehículos; alertas por correo y Telegram
    Route::prefix('expirations')->name('expirations.')->middleware('can:expirations.view')->group(function () {
        Route::get('/', [\App\Modules\HR\Controllers\Web\ExpirationWebController::class, 'index'])->name('index');
        Route::middleware('can:expirations.manage')->group(function () {
            Route::put('/settings', [\App\Modules\HR\Controllers\Web\ExpirationWebController::class, 'updateSettings'])->name('settings');
            Route::post('/test', [\App\Modules\HR\Controllers\Web\ExpirationWebController::class, 'test'])->name('test')->middleware('throttle:5,1');
        });
    });

    Route::prefix('uniforms')->name('uniforms.')->middleware('can:uniforms.manage')->group(function () {
        Route::get('/', [UniformWebController::class, 'index'])->name('index');
        Route::get('/export', [UniformWebController::class, 'export'])->name('export');
        Route::post('/deliveries', [UniformWebController::class, 'storeDelivery'])->name('deliveries.store');
    });

    // Turnos (fijos y rotativos), tolerancias y asignaciones
    Route::prefix('shifts')->name('shifts.')->middleware('can:shifts.manage')->group(function () {
        Route::get('/', [ShiftWebController::class, 'index'])->name('index');
        Route::get('/create', [ShiftWebController::class, 'create'])->name('create');
        Route::post('/', [ShiftWebController::class, 'store'])->name('store');
        Route::get('/{id}/edit', [ShiftWebController::class, 'edit'])->name('edit')->whereNumber('id');
        Route::put('/{id}', [ShiftWebController::class, 'update'])->name('update')->whereNumber('id');
        Route::delete('/{id}', [ShiftWebController::class, 'destroy'])->name('destroy')->whereNumber('id');
        Route::post('/assign', [ShiftWebController::class, 'assign'])->name('assign')->middleware('can:shifts.assign');
        Route::delete('/assignments/{id}', [ShiftWebController::class, 'unassign'])->name('unassign')->middleware('can:shifts.assign')->whereNumber('id');
    });

    // Programación por calendario (QA Ronda 2, HQ-05 y regla 6): RR. HH., administración y el
    // supervisor de agencia (solo sus agencias y su equipo). No requiere crear/editar turnos.
    Route::prefix('shifts')->name('shifts.')->middleware('can:shifts.program')->group(function () {
        Route::get('/schedule', [ScheduleWebController::class, 'index'])->name('schedule');
        Route::post('/schedule', [ScheduleWebController::class, 'save'])->name('schedule.save');
        Route::post('/schedule/repeat', [ScheduleWebController::class, 'repeat'])->name('schedule.repeat');    });

    // Feriados: el control de faltas no registra ausencias en estas fechas
    Route::prefix('holidays')->name('holidays.')->middleware('can:shifts.manage')->group(function () {
        Route::get('/', [HolidayWebController::class, 'index'])->name('index');
        Route::post('/', [HolidayWebController::class, 'store'])->name('store');
        Route::post('/load-peru', [HolidayWebController::class, 'loadPeru'])->name('load-peru');
        Route::delete('/{id}', [HolidayWebController::class, 'destroy'])->name('destroy')->whereNumber('id');
    });

    // Aprobaciones: vacaciones, permisos, licencias, justificaciones y horas extra
    Route::prefix('approvals')->name('approvals.')->middleware('can:leave.approve_team')->group(function () {
        Route::get('/', [ApprovalWebController::class, 'index'])->name('index');
        Route::post('/leave/{id}', [ApprovalWebController::class, 'reviewLeave'])->name('leave.review')->whereNumber('id');
        Route::get('/leave/{id}/attachment', [ApprovalWebController::class, 'leaveAttachment'])->name('leave.attachment')->whereNumber('id');
        Route::post('/overtime/bulk', [ApprovalWebController::class, 'approveOvertimeBulk'])->name('overtime.bulk');
        Route::post('/overtime/{id}', [ApprovalWebController::class, 'reviewOvertime'])->name('overtime.review')->whereNumber('id');
    });
});
