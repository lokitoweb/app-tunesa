<?php

use App\Modules\Ethics\Controllers\Api\EthicsReportController;
use Illuminate\Support\Facades\Route;

// Ethics (API): canal ético anónimo. Fuera de Sanctum a propósito: aunque la app
// tenga sesión, la denuncia no se asocia a nadie. ethics.anonymous:N limita a N
// peticiones por minuto y luego quita IP, user agent y token del request (ver
// StripIdentity); la empresa sale de X-Tenant-Slug.
Route::middleware('tenant')->prefix('ethics')->group(function () {
    Route::get('categories', [EthicsReportController::class, 'categories'])->middleware('ethics.anonymous:30');
    Route::post('cases', [EthicsReportController::class, 'store'])->middleware('ethics.anonymous:5');
    Route::get('cases/{code}', [EthicsReportController::class, 'show'])->middleware('ethics.anonymous:20');
    Route::post('cases/{code}/messages', [EthicsReportController::class, 'message'])->middleware('ethics.anonymous:10');
});

// Denuncia desde la app: con sesión; la identidad queda reservada (solo RRHH la ve)
Route::middleware(['api.tenant', 'throttle:5,1'])->post('ethics/cases/identified', [EthicsReportController::class, 'storeIdentified']);
