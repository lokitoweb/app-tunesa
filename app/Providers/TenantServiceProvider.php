<?php

namespace App\Providers;

use App\Http\Middleware\ResolveTenant;
use App\Services\TenantContext;
use Illuminate\Support\ServiceProvider;

/**
 * TenantServiceProvider
 *
 * Registra:
 * 1. TenantContext como singleton en el Service Container.
 * 2. Los helpers globales viven en app/helpers.php.
 * 3. GlobalScope automático en todos los modelos que implementen HasTenantScope.
 */
class TenantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Registrar TenantContext como Singleton
        // (una sola instancia por request HTTP)
        $this->app->singleton(TenantContext::class, function () {
            return new TenantContext();
        });
    }

    public function boot(): void
    {
        // Los helpers globales current_tenant(), current_tenant_id() y
        // tenant_timezone() están en app/helpers.php (autoload de Composer).
    }
}
