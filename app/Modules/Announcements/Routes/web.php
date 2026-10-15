<?php

use App\Modules\Announcements\Controllers\Web\AnnouncementWebController;
use Illuminate\Support\Facades\Route;

// Announcements (panel web): comunicados a toda la empresa, sede, área, cargo o persona
Route::middleware(['auth', 'web.tenant', 'can:announcements.manage'])->prefix('announcements')->name('announcements.')->group(function () {
    Route::get('/', [AnnouncementWebController::class, 'index'])->name('index');
    Route::get('/create', [AnnouncementWebController::class, 'create'])->name('create');
    Route::post('/', [AnnouncementWebController::class, 'store'])->name('store');
    Route::get('/{id}', [AnnouncementWebController::class, 'show'])->name('show')->whereNumber('id');
    Route::get('/{id}/edit', [AnnouncementWebController::class, 'edit'])->name('edit')->whereNumber('id');
    Route::put('/{id}', [AnnouncementWebController::class, 'update'])->name('update')->whereNumber('id');
    Route::delete('/{id}', [AnnouncementWebController::class, 'destroy'])->name('destroy')->whereNumber('id');
});
