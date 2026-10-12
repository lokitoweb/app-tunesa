<?php

use App\Modules\Onboarding\Controllers\Web\OnboardingWebController;
use Illuminate\Support\Facades\Route;

// Onboarding (panel web): RRHH revisa y aprueba la incorporación; configura las etapas
Route::middleware(['auth', 'web.tenant'])->prefix('onboarding')->name('onboarding.')->group(function () {
    Route::middleware('can:onboarding.manage')->group(function () {
        Route::get('/settings', [OnboardingWebController::class, 'settings'])->name('settings');
        Route::put('/settings', [OnboardingWebController::class, 'updateSettings'])->name('settings.update');
    });

    Route::middleware('can:onboarding.review')->group(function () {
        Route::get('/', [OnboardingWebController::class, 'index'])->name('index');
        Route::get('/{user}', [OnboardingWebController::class, 'show'])->name('show')->whereNumber('user');
        Route::post('/{user}/approve', [OnboardingWebController::class, 'approve'])->name('approve')->whereNumber('user');
        Route::post('/{user}/reject', [OnboardingWebController::class, 'reject'])->name('reject')->whereNumber('user');
    });
});
