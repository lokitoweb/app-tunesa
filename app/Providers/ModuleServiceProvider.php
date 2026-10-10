<?php

namespace App\Providers;

use App\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Monolito modular: registra cada carpeta de app/Modules sin tocar bootstrap/.
 *
 *   Routes/web.php            → grupo 'web'  (panel Inertia / Blade)
 *   Routes/api.php            → grupo 'api'  con prefijo /api (app Flutter, webhooks)
 *   Database/Migrations/      → migraciones del módulo
 *   Console/                  → comandos artisan
 *   Providers/<M>ServiceProvider.php (opcional) → bindings y eventos propios
 *
 * Cada ruta lleva la acción 'module' => <M>; ResolveTenant y SetWebTenant la
 * usan para cerrar los módulos apagados de la empresa (ModuleRegistry).
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach (ModuleRegistry::names() as $module) {
            $provider = "App\\Modules\\{$module}\\Providers\\{$module}ServiceProvider";
            if (class_exists($provider)) {
                $this->app->register($provider);
            }
        }
    }

    public function boot(): void
    {
        foreach (ModuleRegistry::names() as $module) {
            $path = app_path("Modules/{$module}");

            if (! $this->app->routesAreCached()) {
                if (is_file($web = "{$path}/Routes/web.php")) {
                    Route::group(['middleware' => 'web', 'module' => $module], $web);
                }
                if (is_file($api = "{$path}/Routes/api.php")) {
                    Route::group(['middleware' => 'api', 'prefix' => 'api', 'module' => $module], $api);
                }
            }
            if (is_dir($migrations = "{$path}/Database/Migrations")) {
                $this->loadMigrationsFrom($migrations);
            }
            $this->commands(array_map(
                fn (string $file) => "App\\Modules\\{$module}\\Console\\" . basename($file, '.php'),
                glob("{$path}/Console/*.php") ?: [],
            ));
        }
    }
}
