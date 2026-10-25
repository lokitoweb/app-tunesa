<?php

use App\Modules\Birthdays\Controllers\Api\BirthdayController;
use Illuminate\Support\Facades\Route;

// Birthdays (API): cumpleaños del personal en la app.
// Convenciones de middleware en Core/Routes/api.php.
Route::middleware(['api.tenant', 'permission:birthdays.view'])->prefix('birthdays')->group(function () {
    Route::get('/',         [BirthdayController::class, 'index']);
    Route::get('upcoming',  [BirthdayController::class, 'upcoming']);
    Route::get('{id}',      [BirthdayController::class, 'show'])->whereNumber('id');
    // Regalos animados: solo el día del cumpleaños (zona horaria de la empresa)
    Route::get('{id}/gifts',  [BirthdayController::class, 'gifts'])->whereNumber('id');
    Route::post('{id}/gifts', [BirthdayController::class, 'sendGift'])->whereNumber('id')->middleware('throttle:30,1');
});
