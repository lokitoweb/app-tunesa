<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
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
 *
 * Super Admin: no pertenece a ninguna empresa. Elige cuál administrar con el
 * selector del panel (sesión `admin_tenant_id`); sin empresa elegida solo ve
 * el dashboard consolidado de la plataforma, nunca módulos sin scope.
 */
class SetWebTenant
{
    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($user->isSuperAdmin()) {
            return $this->handleSuperAdmin($request, $next);
        }

        $tenant = $user->tenant;

        if (! $tenant || ! $tenant->isActive() || $user->status !== 'active' || ! $user->hasPermission('dashboard.view')) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login')
                ->withErrors(['email' => 'Tu cuenta no tiene acceso al panel o la empresa está suspendida.']);
        }

        $this->activate($tenant);

        return $next($request);
    }

    /** Rutas que el Super Admin puede usar sin empresa elegida */
    public const PLATFORM_ROUTES = ['dashboard', 'dashboard.live-stream', 'logout', 'admin.tenant.switch'];

    private function handleSuperAdmin(Request $request, Closure $next): Response
    {
        $tenantId = $request->session()->get('admin_tenant_id');
        $tenant   = $tenantId ? Tenant::find($tenantId) : null;

        if ($tenant) {
            $this->activate($tenant);

            return $next($request);
        }

        $request->session()->forget('admin_tenant_id');

        if (! in_array($request->route()?->getName(), self::PLATFORM_ROUTES, true)) {
            return redirect()->route('dashboard')
                ->with('error', 'Elige una empresa en el selector superior para administrar sus módulos.');
        }

        return $next($request);
    }

    private function activate(Tenant $tenant): void
    {
        $this->tenantContext->set($tenant);
        config(['app.timezone' => $tenant->timezone]);
        date_default_timezone_set($tenant->timezone);
    }
}
