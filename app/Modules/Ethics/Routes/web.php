<?php

use App\Modules\Ethics\Controllers\Web\EthicsWebController;
use Illuminate\Support\Facades\Route;

// Ethics (panel web): bandeja de denuncias del canal ético
Route::middleware(['auth', 'web.tenant', 'can:ethics.investigate'])->prefix('ethics')->name('ethics.')->group(function () {
    Route::get('/', [EthicsWebController::class, 'index'])->name('index');
    Route::get('/{case}', [EthicsWebController::class, 'show'])->name('show')->whereNumber('case');
    Route::post('/{case}/reply', [EthicsWebController::class, 'reply'])->name('reply')->whereNumber('case');
    Route::put('/{case}/status', [EthicsWebController::class, 'status'])->name('status')->whereNumber('case');
    Route::get('/{case}/photos/{attachment}', [EthicsWebController::class, 'photo'])->name('photo')->whereNumber(['case', 'attachment']);
});
