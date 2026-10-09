<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware: SetWebTenant
 *
 * Panel web (sesión): activa el TenantContext con la empresa del usuario
 * logueado, para que los global scopes (TenantScope) apliquen igual que en
 * la API. También cierra el panel a colaboradores y a empresas suspendidas.
 */
class SetWebTenant
{
    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->isSuperAdmin()) {
            return $next($request);
        }

        $tenant = $user->tenant;

        if (! $tenant || ! $tenant->isActive() || $user->status !== 'active' || ! $user->hasPermission('dashboard.view')) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login')
                ->withErrors(['email' => 'Tu cuenta no tiene acceso al panel o la empresa está suspendida.']);
        }

        $this->tenantContext->set($tenant);
        config(['app.timezone' => $tenant->timezone]);
        date_default_timezone_set($tenant->timezone);

        return $next($request);
    }
}
