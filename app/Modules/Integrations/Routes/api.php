<?php

use App\Modules\Integrations\Controllers\Api\WebhookReceiverController;
use App\Modules\Integrations\Controllers\GeocodeController;
use Illuminate\Support\Facades\Route;

// Integrations (API): receptor de webhooks de la plataforma y geocodificación.

// Receptor de la plataforma: verifica la firma HMAC del envío (sin sesión ni token)
Route::post('v1/webhooks/receiver/{slug?}', [WebhookReceiverController::class, 'receive'])
    ->middleware('throttle:60,1')->where('slug', '[a-z0-9-]+')->name('integrations.receiver');

// Abrir el receptor en el navegador (GET) confirma que está activo; los eventos llegan por POST
Route::get('v1/webhooks/receiver/{slug?}', fn () => response()->json([
    'success' => true,
    'message' => 'Receptor de webhooks activo. Envía los eventos por POST.',
]))->middleware('throttle:60,1')->where('slug', '[a-z0-9-]+');

Route::middleware('api.tenant')->group(function () {
    Route::get('geocode/reverse', [GeocodeController::class, 'reverse'])->middleware('throttle:60,1');
});
