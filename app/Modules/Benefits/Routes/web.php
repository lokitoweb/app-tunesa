<?php

use App\Modules\Benefits\Controllers\Web\BenefitWebController;
use Illuminate\Support\Facades\Route;

// Benefits (panel web): catálogo de beneficios, categorías y público
Route::middleware(['auth', 'web.tenant', 'can:benefits.manage'])->prefix('benefits')->name('benefits.')->group(function () {
    Route::get('/', [BenefitWebController::class, 'index'])->name('index');
    Route::get('/create', [BenefitWebController::class, 'create'])->name('create');
    Route::post('/', [BenefitWebController::class, 'store'])->name('store');
    Route::get('/{id}/edit', [BenefitWebController::class, 'edit'])->name('edit')->whereNumber('id');
    Route::put('/{id}', [BenefitWebController::class, 'update'])->name('update')->whereNumber('id');
    Route::post('/{id}/status', [BenefitWebController::class, 'status'])->name('status')->whereNumber('id');
    Route::delete('/{id}', [BenefitWebController::class, 'destroy'])->name('destroy')->whereNumber('id');
    Route::get('/categories', [BenefitWebController::class, 'categories'])->name('categories');
    Route::post('/categories', [BenefitWebController::class, 'storeCategory'])->name('categories.store');
    Route::put('/categories/{id}', [BenefitWebController::class, 'updateCategory'])->name('categories.update')->whereNumber('id');
    Route::delete('/categories/{id}', [BenefitWebController::class, 'destroyCategory'])->name('categories.destroy')->whereNumber('id');
});
