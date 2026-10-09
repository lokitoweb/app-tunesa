<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware: SetWebTenant
 *
 * Panel web (sesión): activa el TenantContext con la empresa del usuario
 * logueado, para que los global scopes (TenantScope) apliquen igual que en
 * la API. También cierra el panel a colaboradores y a empresas suspendidas.
 *
 * Super Admin: no pertenece a ninguna empresa. Con el selector del panel
 * (sesión `admin_tenant_id`) entra a una empresa y todo queda con su scope.
 * Sin empresa elegida está en la "vista global": puede abrir cualquier módulo
 * y los listados muestran registros de todas las empresas (nunca se le
 * redirige al dashboard). Lo único que exige elegir empresa es crear o
 * editar, porque cada registro tiene que nacer con su tenant_id.
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

    /** Rutas de escritura permitidas en la vista global (no crean registros de empresa) */
    public const GLOBAL_WRITE_ROUTES = ['logout', 'admin.tenant.switch'];

    /** Formularios GET que preparan una escritura y por eso necesitan empresa */
    public const GLOBAL_FORM_ROUTES = ['*.create', '*.edit', 'payslips.bulk'];

    private function handleSuperAdmin(Request $request, Closure $next): Response
    {
        $tenantId = $request->session()->get('admin_tenant_id');
        $tenant   = $tenantId ? Tenant::find($tenantId) : null;

        if ($tenant) {
            $this->activate($tenant);
            View::share('globalView', false);

            return $next($request);
        }

        $request->session()->forget('admin_tenant_id');
        View::share('globalView', true);
        View::share('tenantNames', Tenant::orderBy('company_name')->pluck('company_name', 'id'));

        if ($this->needsTenantToWrite($request)) {
            $message = 'Para crear o editar, elige la empresa en el selector superior.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 409);
            }

            return redirect()->back(fallback: $this->moduleIndex($request))->with('error', $message);
        }

        return $next($request);
    }

    /** Vuelve al listado del mismo módulo (employees.create → employees.index) */
    private function moduleIndex(Request $request): string
    {
        $index = Str::before($request->route()?->getName() ?? '', '.') . '.index';

        return Route::has($index) ? route($index) : route('dashboard');
    }

    private function needsTenantToWrite(Request $request): bool
    {
        $route = $request->route()?->getName() ?? '';

        if (in_array($route, self::GLOBAL_WRITE_ROUTES, true)) {
            return false;
        }

        if (! $request->isMethodSafe()) {
            return true;
        }

        return Str::is(self::GLOBAL_FORM_ROUTES, $route);
    }

    private function activate(Tenant $tenant): void
    {
        $this->tenantContext->set($tenant);
        config(['app.timezone' => $tenant->timezone]);
        date_default_timezone_set($tenant->timezone);
    }
}
