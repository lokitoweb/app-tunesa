<?php

use App\Modules\Ethics\Controllers\Web\EthicsPublicController;
use App\Modules\Ethics\Controllers\Web\EthicsWebController;
use Illuminate\Support\Facades\Route;

// Ethics (panel web): bandeja de denuncias del canal ético
Route::middleware(['auth', 'web.tenant', 'can:ethics.investigate'])->prefix('ethics')->name('ethics.')->group(function () {
    Route::get('/', [EthicsWebController::class, 'index'])->name('index');
    Route::get('/{case}', [EthicsWebController::class, 'show'])->name('show')->whereNumber('case');
    Route::post('/{case}/identity', [EthicsWebController::class, 'identity'])->name('identity')->whereNumber('case')->middleware('can:ethics.view_identity');
    Route::post('/{case}/reply', [EthicsWebController::class, 'reply'])->name('reply')->whereNumber('case');
    Route::put('/{case}/status', [EthicsWebController::class, 'status'])->name('status')->whereNumber('case');
    Route::get('/{case}/photos/{attachment}', [EthicsWebController::class, 'photo'])->name('photo')->whereNumber(['case', 'attachment']);
});

// Canal ético en la web, sin iniciar sesión. Sin sesión, cookies ni CSRF a
// propósito (no queda rastro de la visita); StripIdentity limita los intentos
// y borra IP y user agent del request.
Route::prefix('canal-etico/{slug}')->name('ethics.public')->where(['slug' => '[a-z0-9-]+'])
    ->withoutMiddleware([
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        \App\Modules\Core\Middleware\HandleInertiaRequests::class,
    ])
    ->group(function () {
        Route::get('/', [EthicsPublicController::class, 'show'])->name('')->middleware('ethics.anonymous:30');
        Route::post('/denuncias', [EthicsPublicController::class, 'store'])->name('.store')->middleware('ethics.anonymous:5');
        Route::post('/seguimiento', [EthicsPublicController::class, 'follow'])->name('.follow')->middleware('ethics.anonymous:20');
        Route::post('/seguimiento/mensaje', [EthicsPublicController::class, 'message'])->name('.message')->middleware('ethics.anonymous:10');
    });
