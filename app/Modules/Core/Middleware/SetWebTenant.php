<?php

namespace App\Modules\Core\Middleware;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Services\TenantContext;
use App\Modules\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Inertia\Inertia;
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
 * redirige al dashboard). Lo único que exige empresa es crear o editar,
 * porque cada registro tiene que nacer con su tenant_id: con una sola empresa
 * activa se usa esa; con varias se abre el selector "¿En qué empresa?".
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

        // Sin panel pero con portal: su lugar es Mi espacio (p. ej. "/" o un enlace viejo del panel)
        if ($tenant?->isActive() && $user->status === 'active' && ! $user->hasPermission('dashboard.view') && $user->hasPermission('portal.access')) {
            return $request->header('X-Inertia') ? Inertia::location(route('portal.home')) : redirect()->route('portal.home');
        }

        if (! $tenant || ! $tenant->isActive() || $user->status !== 'active' || ! $user->hasPermission('dashboard.view')) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login')
                ->withErrors(['email' => 'Tu cuenta no tiene acceso al panel o la empresa está suspendida.']);
        }

        $this->activate($tenant);
        View::share('globalView', false);

        return $next($request);
    }

    /** Rutas de escritura permitidas en la vista global (no crean registros de empresa) */
    public const GLOBAL_WRITE_ROUTES = [
        'logout', 'session.keep-alive', 'admin.tenant.switch', 'admin.tenant.choose',
        'notifications.read', 'notifications.read-all', 'notifications.toggle-read', 'notifications.destroy',
        'account.password', 'account.profile', 'account.revoke-tokens',
        // Sin empresa elegida edita los valores por defecto de la plataforma (tenant_settings global)
        'settings.branding.update', 'settings.integrations.update',
        // Pruebas de integraciones de la plataforma (no crean registros de empresa)
        'settings.branding.test-webhook', 'settings.branding.test-notification', 'settings.branding.test-push',
        'employees.file.reveal',
        'ethics.identity',
        'ethics.reply',
        'ethics.status',
        'ethics.assign',
    ];

    /**
     * Rutas que eligen la empresa en el propio formulario (selector "Empresa"
     * para crear, o la empresa del registro al editar): funcionan en la vista global.
     */
    public const GLOBAL_TENANT_PICKER_ROUTES = [
        'branches.create', 'branches.store', 'branches.edit', 'branches.update', 'branches.destroy',
        'employees.store', 'employees.edit', 'employees.update',
        'employees.file', 'employees.file.profile', 'employees.file.payment-review', 'employees.file.reveal', 'employees.file.upload',
        'employees.toggle-status', 'employees.unlock', 'employees.reset-access',
        // Las empresas mismas (Super Admin): no pertenecen a ninguna empresa
        'companies.create', 'companies.store', 'companies.edit', 'companies.update',
    ];

    /** Formularios GET que preparan una escritura y por eso necesitan empresa */
    public const GLOBAL_FORM_ROUTES = ['*.create', '*.edit', 'payslips.bulk'];

    /**
     * Pantallas de dinero (saldos, totales, correlativos, reportes): cada empresa es un universo aparte,
     * así que en la vista global también se pregunta la empresa en vez de sumar todas.
     */
    public const SINGLE_COMPANY_ROUTES = [
        'treasury.*', 'petty-cash.*', 'fixed-costs.*', 'cash-flow.*', 'finance-reports.*',
        'payroll-payments.*', 'inventory.*', 'fuel.*', 'purchases.*',
    ];

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

        // Reenvío desde el selector de empresa: el formulario vuelve con la empresa elegida
        if (! $request->isMethodSafe() && $request->filled('_tenant')) {
            $chosen = Tenant::whereKey((int) $request->input('_tenant'))->where('status', 'active')->first();
            $request->request->remove('_tenant');

            if ($chosen) {
                $request->session()->put('admin_tenant_id', $chosen->id);
                $this->activate($chosen);
                View::share('globalView', false);

                return $next($request);
            }
        }

        if ($this->needsTenantToWrite($request)) {
            $active = self::activeTenants();

            // Una sola empresa activa: el registro nace en ella sin preguntar
            if ($active->count() === 1) {
                $this->activate(Tenant::findOrFail($active->first()->id));
                View::share('globalView', false);

                return $next($request);
            }

            return $this->askForTenant($request, $active);
        }

        View::share('globalView', true);
        View::share('tenantNames', Tenant::orderBy('company_name')->pluck('company_name', 'id'));

        return $next($request);
    }

    /** Empresas activas, para el selector de "¿en qué empresa?" */
    public static function activeTenants(): Collection
    {
        return Tenant::where('status', 'active')->orderBy('company_name')->get(['id', 'company_name', 'ruc']);
    }

    /**
     * Vista global con varias empresas: en lugar de un aviso, se pregunta en qué
     * empresa crear. Un formulario enviado se reenvía con la empresa elegida
     * (no se pierde lo escrito); una pantalla de crear o editar se abre después
     * de elegir.
     */
    private function askForTenant(Request $request, Collection $tenants): Response
    {
        $message = Str::is(self::SINGLE_COMPANY_ROUTES, $request->route()?->getName() ?? '')
            ? 'Elige la empresa: los montos y totales son de una sola empresa.'
            : 'Elige la empresa en la que quieres crear o editar.';
        $isWrite = ! $request->isMethodSafe();
        $back    = $isWrite ? $this->moduleIndex($request) : $request->fullUrl();
        $chooser = route('admin.tenant.choose', ['next' => self::relativeUrl($back)]);

        if ($request->header('X-Inertia')) {
            return Inertia::location($chooser);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'tenant_required' => true, 'choose_url' => $chooser], 409);
        }

        // Un formulario con archivos no se puede reenviar: se elige y se vuelve al listado
        if (! $isWrite) {
            return redirect()->to($chooser);
        }
        if ($request->allFiles()) {
            return redirect()->to($chooser)->withInput($request->except(['_token', '_method', 'password', 'password_confirmation']));
        }

        View::share('globalView', true);

        return response()->view('tenant-picker', [
            'tenants' => $tenants,
            'next'    => self::relativeUrl($back),
            'cancel'  => $back,
            'replay'  => [
                'action' => $request->fullUrl(),
                'method' => $request->method(),
                'fields' => Arr::dot($request->except(['_token', '_method'])),
            ],
        ], 409);
    }

    /** Ruta relativa y del mismo sitio (evita redirecciones abiertas con ?next=) */
    public static function relativeUrl(?string $url): string
    {
        $path = (string) parse_url((string) $url, PHP_URL_PATH);
        $query = parse_url((string) $url, PHP_URL_QUERY);

        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return '/dashboard';
        }

        return $path . ($query ? '?' . $query : '');
    }

    /** Vuelve al listado del mismo módulo (employees.create → employees.index) */
    private function moduleIndex(Request $request): string
    {
        return self::indexForRoute($request->route()?->getName());
    }

    public static function indexForRoute(?string $name): string
    {
        $index = Str::before($name ?? '', '.') . '.index';

        return Route::has($index) ? route($index) : route('dashboard');
    }

    /** Listado del módulo de una URL del panel (para "Cancelar" en el selector) */
    public static function indexForUrl(string $url): string
    {
        try {
            $name = app('router')->getRoutes()->match(Request::create($url))->getName();
        } catch (\Throwable) {
            $name = null;
        }

        return self::indexForRoute($name);
    }

    private function needsTenantToWrite(Request $request): bool
    {
        $route = $request->route()?->getName() ?? '';

        if (in_array($route, self::GLOBAL_WRITE_ROUTES, true) || in_array($route, self::GLOBAL_TENANT_PICKER_ROUTES, true)) {
            return false;
        }

        if (! $request->isMethodSafe()) {
            return true;
        }

        return Str::is([...self::GLOBAL_FORM_ROUTES, ...self::SINGLE_COMPANY_ROUTES], $route);
    }

    /** Activa la empresa para la petición (scopes, zona horaria, módulos); lo usa también el portal */
    public function activate(Tenant $tenant): void
    {
        abort_if(ModuleRegistry::blocks(request(), $tenant), 403, 'Este módulo no está activo para tu empresa.');

        $this->tenantContext->set($tenant);
        config(['app.timezone' => $tenant->timezone]);
        date_default_timezone_set($tenant->timezone);
    }
}
