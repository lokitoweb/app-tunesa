<?php

use App\Modules\Attendance\Controllers\Web\AttendanceEditWebController;
use App\Modules\Attendance\Controllers\Web\AttendanceWebController;
use App\Modules\Attendance\Controllers\Web\CalendarWebController;
use App\Modules\Attendance\Controllers\Web\HoursReportWebController;
use App\Modules\Attendance\Controllers\Web\LiveBoardController;
use App\Modules\Attendance\Controllers\Web\PunchAttemptWebController;
use App\Modules\Attendance\Controllers\Web\ReportWebController;
use Illuminate\Support\Facades\Route;

// Attendance (panel web): marcaciones, faltas, reportes y tablero en vivo
Route::middleware(['auth', 'web.tenant'])->group(function () {
    // Control de Asistencias, faltas y auditoría GPS / Selfie
    Route::prefix('attendance')->name('attendance.')->middleware('can:attendance.view_all')->group(function () {
        Route::get('/', [AttendanceWebController::class, 'index'])->name('index');
        Route::get('/attempts', [PunchAttemptWebController::class, 'index'])->name('attempts');
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

    // Calendario con semáforo (RRHH: toda la empresa; supervisor: su equipo)
    Route::get('/attendance-calendar', [CalendarWebController::class, 'index'])->name('attendance.calendar');
    Route::get('/attendance-calendar/excel', [CalendarWebController::class, 'excel'])->name('attendance.calendar.excel');
    Route::get('/attendance-calendar/day', [CalendarWebController::class, 'day'])->name('attendance.calendar.day');
    Route::get('/attendance-calendar/person/{id}', [CalendarWebController::class, 'person'])->name('attendance.calendar.person');

    // Corrección manual de una jornada (RRHH: toda la empresa; supervisor: su equipo)
    Route::middleware('can:attendance.edit')->group(function () {
        Route::get('/attendance-calendar/person/{id}/day/{date}', [AttendanceEditWebController::class, 'edit'])->name('attendance.manual.edit')
            ->whereNumber('id')->where('date', '\\d{4}-\\d{2}-\\d{2}');
        Route::put('/attendance-calendar/person/{id}/day/{date}', [AttendanceEditWebController::class, 'update'])->name('attendance.manual.update')
            ->whereNumber('id')->where('date', '\\d{4}-\\d{2}-\\d{2}');
    });
    Route::get('/live/data', [LiveBoardController::class, 'data'])->name('live.data');
});
