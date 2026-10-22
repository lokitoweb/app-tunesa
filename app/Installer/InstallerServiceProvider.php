<?php

namespace App\Installer;

use App\Installer\Http\InstallerController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Asistente de instalación (/install). En un servidor nuevo crea el .env
 * desde .env.example y genera APP_KEY antes de que se use la sesión.
 */
class InstallerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! InstallState::enabled() || $this->app->runningUnitTests() || is_file(InstallState::lockFile())) {
            return;
        }

        try {
            $env = EnvWriter::forApp();
            $env->ensureExists();
            if (empty(config('app.key'))) {
                $key = 'base64:' . base64_encode(random_bytes(32));
                $env->set(['APP_KEY' => $key]);
                config(['app.key' => $key]);
            }
        } catch (\Throwable) {
            // El paso 1 muestra que el .env no tiene permisos de escritura
        }
    }

    public function boot(): void
    {
        // Siempre registradas (también con route:cache); el middleware las cierra con 404 si ya está instalado
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware(['web', 'throttle:60,1'])->prefix('install')->name('installer.')->group(function () {
            Route::get('/', [InstallerController::class, 'requirements'])->name('requirements');
            Route::get('database', [InstallerController::class, 'database'])->name('database');
            Route::post('database/test', [InstallerController::class, 'testDatabase'])->middleware('throttle:10,1')->name('database.test');
            Route::post('database', [InstallerController::class, 'saveDatabase'])->name('database.save');
            Route::get('migrate', [InstallerController::class, 'migrate'])->name('migrate');
            Route::post('migrate', [InstallerController::class, 'runMigrations'])->name('migrate.run');
            Route::get('admin', [InstallerController::class, 'admin'])->name('admin');
            Route::post('admin', [InstallerController::class, 'saveAdmin'])->name('admin.save');
        });
    }
}
