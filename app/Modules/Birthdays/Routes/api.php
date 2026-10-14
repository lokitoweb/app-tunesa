<?php

use App\Modules\Birthdays\Controllers\Api\BirthdayController;
use Illuminate\Support\Facades\Route;

// Birthdays (API): cumpleaños del personal en la app.
// Convenciones de middleware en Core/Routes/api.php.
Route::middleware(['api.tenant', 'permission:birthdays.view'])->prefix('birthdays')->group(function () {
    Route::get('/',         [BirthdayController::class, 'index']);
    Route::get('upcoming',  [BirthdayController::class, 'upcoming']);
    Route::get('{id}',      [BirthdayController::class, 'show'])->whereNumber('id');
});
