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
 * 2. Helper global current_tenant() para acceder al tenant fácilmente.
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
        // =====================================================================
        // HELPER GLOBAL: current_tenant()
        // =====================================================================
        // Permite acceder al tenant activo desde cualquier parte:
        //   $tenant = current_tenant();
        //   $tenantId = current_tenant_id();
        if (! function_exists('current_tenant')) {
            /**
             * Retorna el tenant activo en la request actual.
             */
            function current_tenant(): \App\Models\Tenant
            {
                return app(TenantContext::class)->get();
            }
        }

        if (! function_exists('current_tenant_id')) {
            /**
             * Retorna el ID del tenant activo.
             * Útil para queries: User::where('tenant_id', current_tenant_id())
             */
            function current_tenant_id(): int
            {
                return app(TenantContext::class)->id();
            }
        }

        if (! function_exists('tenant_timezone')) {
            /**
             * Retorna la zona horaria del tenant activo.
             * Útil para Carbon: now(tenant_timezone())
             */
            function tenant_timezone(): string
            {
                $ctx = app(TenantContext::class);
                return $ctx->resolved() ? $ctx->get()->timezone : config('app.timezone');
            }
        }
    }
}
