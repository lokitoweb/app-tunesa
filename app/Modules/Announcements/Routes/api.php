<?php

use App\Modules\Announcements\Controllers\Api\AnnouncementController;
use Illuminate\Support\Facades\Route;

// Announcements (API): comunicados de RR. HH. en la app.
// Convenciones de middleware en Core/Routes/api.php.
Route::middleware(['api.tenant', 'permission:announcements.view_own'])->prefix('announcements')->group(function () {
    Route::get('/',    [AnnouncementController::class, 'index']);
    Route::get('{id}', [AnnouncementController::class, 'show'])->whereNumber('id');
});
