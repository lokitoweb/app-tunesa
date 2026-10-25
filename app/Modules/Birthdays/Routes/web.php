<?php

use App\Modules\Birthdays\Controllers\Web\BirthdayWebController;
use Illuminate\Support\Facades\Route;

// Birthdays (panel web): calendario de cumpleaños y ajustes del aviso
Route::middleware(['auth', 'web.tenant', 'can:birthdays.manage'])->prefix('birthdays')->name('birthdays.')->group(function () {
    Route::get('/', [BirthdayWebController::class, 'index'])->name('index');
    Route::put('/settings', [BirthdayWebController::class, 'settings'])->name('settings');
    Route::get('/export', [BirthdayWebController::class, 'export'])->name('export');
    Route::get('/gifts/export', [BirthdayWebController::class, 'exportGifts'])->name('gifts.export');
});
