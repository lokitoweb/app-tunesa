<?php

use App\Modules\Benefits\Controllers\Api\BenefitController;
use Illuminate\Support\Facades\Route;

// Benefits (API): beneficios del colaborador en la app.
// Convenciones de middleware en Core/Routes/api.php.
Route::middleware(['api.tenant', 'permission:benefits.view_own'])->prefix('benefits')->group(function () {
    Route::get('/',     [BenefitController::class, 'index']);
    Route::get('{id}',  [BenefitController::class, 'show'])->whereNumber('id');
});
