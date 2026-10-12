<?php

use App\Modules\Onboarding\Controllers\Api\OnboardingController;
use Illuminate\Support\Facades\Route;

// Onboarding (API): incorporación del colaborador en la app.
// Convenciones de middleware en Core/Routes/api.php.
Route::middleware('api.tenant')->prefix('onboarding')->group(function () {
    Route::get('/',       [OnboardingController::class, 'show']);
    Route::post('submit', [OnboardingController::class, 'submit'])->middleware('throttle:10,1');
});
