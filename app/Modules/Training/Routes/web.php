<?php

use App\Modules\Training\Controllers\Web\TrainingWebController;
use Illuminate\Support\Facades\Route;

// Training (panel web): cursos, asignación y avance
Route::middleware(['auth', 'web.tenant', 'can:training.manage'])->prefix('training')->name('training.')->group(function () {
    Route::get('/', [TrainingWebController::class, 'index'])->name('index');
    Route::get('/create', [TrainingWebController::class, 'create'])->name('create');
    Route::post('/', [TrainingWebController::class, 'store'])->name('store');
    Route::get('/{id}', [TrainingWebController::class, 'show'])->name('show')->whereNumber('id');
    Route::get('/{id}/edit', [TrainingWebController::class, 'edit'])->name('edit')->whereNumber('id');
    Route::put('/{id}', [TrainingWebController::class, 'update'])->name('update')->whereNumber('id');
    Route::post('/{id}/status', [TrainingWebController::class, 'status'])->name('status')->whereNumber('id');
    Route::delete('/{id}', [TrainingWebController::class, 'destroy'])->name('destroy')->whereNumber('id');
    Route::get('/{id}/export', [TrainingWebController::class, 'export'])->name('export')->whereNumber('id');
    Route::get('/{id}/lessons/{lesson}/file', [TrainingWebController::class, 'lessonFile'])->name('lesson-file')->whereNumber(['id', 'lesson']);
    Route::get('/{id}/certificates/{enrollment}', [TrainingWebController::class, 'certificate'])->name('certificate')->whereNumber(['id', 'enrollment']);
});
