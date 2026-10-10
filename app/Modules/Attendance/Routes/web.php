<?php

use App\Modules\Attendance\Controllers\Web\AttendanceWebController;
use App\Modules\Attendance\Controllers\Web\HoursReportWebController;
use App\Modules\Attendance\Controllers\Web\LiveBoardController;
use App\Modules\Attendance\Controllers\Web\ReportWebController;
use Illuminate\Support\Facades\Route;

// Attendance (panel web): marcaciones, faltas, reportes y tablero en vivo
Route::middleware(['auth', 'web.tenant'])->group(function () {
    // Control de Asistencias, faltas y auditoría GPS / Selfie
    Route::prefix('attendance')->name('attendance.')->middleware('can:attendance.view_all')->group(function () {
        Route::get('/', [AttendanceWebController::class, 'index'])->name('index');
        Route::get('/{id}', [AttendanceWebController::class, 'show'])->name('show')->whereNumber('id');
        Route::get('/{id}/evidence', [AttendanceWebController::class, 'evidence'])->name('evidence')->whereNumber('id');
        Route::post('/{id}/review', [AttendanceWebController::class, 'reviewJustification'])->name('review')->middleware('can:attendance.review')->whereNumber('id');
        Route::get('/{id}/justification-file', [AttendanceWebController::class, 'justificationFile'])->name('justification-file')->whereNumber('id');
    });

    // Reportes analíticos y exportación
    Route::prefix('reports')->name('reports.')->middleware('can:reports.view')->group(function () {
        Route::get('/', [ReportWebController::class, 'index'])->name('index');
        Route::get('/export/excel', [ReportWebController::class, 'exportExcel'])->name('export.excel')->middleware('can:reports.export');
        Route::get('/export/pdf', [ReportWebController::class, 'exportPdf'])->name('export.pdf')->middleware('can:reports.export');

        // Reporte de horas y faltas (vista previa + Excel / PDF)
        Route::get('/hours', [HoursReportWebController::class, 'index'])->name('hours');
        Route::get('/hours/excel', [HoursReportWebController::class, 'excel'])->name('hours.excel')->middleware('can:reports.export');
        Route::get('/hours/pdf', [HoursReportWebController::class, 'pdf'])->name('hours.pdf')->middleware('can:reports.export');

        // Reporte mensual automático (enlace firmado de la notificación)
        Route::get('/monthly/{tenant}/{period}', [HoursReportWebController::class, 'monthly'])->name('monthly.download')
            ->whereNumber('tenant')->where('period', '\\d{4}-\\d{2}')
            ->middleware(['signed', 'can:reports.export']);
    });

    // Tablero en vivo (supervisores: solo su equipo)
    Route::get('/live', [LiveBoardController::class, 'index'])->name('live.index');
    Route::get('/live/data', [LiveBoardController::class, 'data'])->name('live.data');
});
