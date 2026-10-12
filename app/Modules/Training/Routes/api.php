<?php

use App\Modules\Training\Controllers\Api\TrainingController;
use Illuminate\Support\Facades\Route;

// Training (API): capacitaciones del colaborador en la app.
// Convenciones de middleware en Core/Routes/api.php.
Route::middleware(['api.tenant', 'permission:training.view_own'])->prefix('trainings')->group(function () {
    Route::get('/',                                    [TrainingController::class, 'index']);
    Route::get('{course}',                             [TrainingController::class, 'show'])->whereNumber('course');
    Route::post('{course}/lessons/{lesson}/complete',  [TrainingController::class, 'completeLesson'])->whereNumber(['course', 'lesson']);
    Route::get('{course}/lessons/{lesson}/file',       [TrainingController::class, 'lessonFile'])->whereNumber(['course', 'lesson']);
    Route::post('{course}/quiz',                       [TrainingController::class, 'submitQuiz'])->whereNumber('course')->middleware('throttle:20,1');
    Route::get('{course}/certificate',                 [TrainingController::class, 'certificate'])->whereNumber('course')->middleware('throttle:20,1');
});
