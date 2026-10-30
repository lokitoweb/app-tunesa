<?php

use App\Modules\Integrations\Controllers\GeocodeController;
use App\Modules\Integrations\Controllers\Web\IntegrationsWebController;
use Illuminate\Support\Facades\Route;

// Integrations (panel web): geocodificación, webhooks, push e historial de envíos
Route::middleware(['auth', 'web.tenant'])->group(function () {
    // Geocodificación (buscar direcciones / dirección de un punto) con el proveedor de la empresa
    Route::middleware('throttle:60,1,geocode')->prefix('geocode')->name('geocode.')->group(function () {
        Route::get('/search', [GeocodeController::class, 'search'])->name('search');
        Route::get('/reverse', [GeocodeController::class, 'reverse'])->name('reverse');
    });

    Route::middleware('can:company.configure')->group(function () {
        // Pantalla de integraciones (Vue): webhook, geocodificación y push
        Route::get('/settings/integrations', [IntegrationsWebController::class, 'index'])->name('settings.integrations');
        Route::put('/settings/integrations', [IntegrationsWebController::class, 'update'])->name('settings.integrations.update');

        // Pruebas en vivo e historial de envíos (webhook_deliveries).
        // Conservan el nombre settings.branding.* de cuando eran una pestaña de "Marca y app".
        Route::prefix('settings/branding')->name('settings.branding')->group(function () {
            Route::get('/deliveries', [IntegrationsWebController::class, 'deliveries'])->name('.deliveries');
            Route::middleware('throttle:20,1,integration-tests')->group(function () {
                Route::post('/test-webhook', [IntegrationsWebController::class, 'testWebhook'])->name('.test-webhook');
                Route::post('/test-notification', [IntegrationsWebController::class, 'testNotification'])->name('.test-notification');
                Route::post('/test-push', [IntegrationsWebController::class, 'testPush'])->name('.test-push');
                Route::post('/test-mail', [IntegrationsWebController::class, 'testMail'])->name('.test-mail');
            });
            Route::post('/regenerate-webhook-secret', [IntegrationsWebController::class, 'regenerateWebhookSecret'])
                ->middleware('throttle:10,1')->name('.regenerate-webhook-secret');
        });
    });
});
