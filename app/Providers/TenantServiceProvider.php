<?php

namespace App\Providers;

use App\Http\Middleware\ResolveTenant;
use App\Services\TenantContext;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * TenantServiceProvider
 *
 * Registra:
 * 1. TenantContext como singleton en el Service Container.
 * 2. Los helpers globales viven en app/helpers.php.
 * 3. El GlobalScope vive en el trait App\Models\Concerns\BelongsToTenant.
 */
class TenantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Registrar TenantContext como instancia "scoped": una por request
        // (también en Octane y en workers de cola, que la reinician).
        $this->app->scoped(TenantContext::class, function () {
            return new TenantContext();
        });
    }

    public function boot(): void
    {
        // Cada request arranca sin tenant, para no heredar los global scopes de
        // una request anterior en el mismo proceso (tests, Octane). Se limpia al
        // INICIO y no al final: las respuestas streamed (exportaciones) se
        // generan después de que el kernel terminó y aún necesitan el scope.
        Event::listen(RouteMatched::class, fn () => $this->app->make(TenantContext::class)->clear());

        // Los helpers globales current_tenant(), current_tenant_id() y
        // tenant_timezone() están en app/helpers.php (autoload de Composer).
    }
}
