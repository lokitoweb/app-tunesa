<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LoginGuard;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * AuthController (Web)
 *
 * Gestiona el acceso al panel web para administradores, RRHH y supervisores.
 * Soporta autenticación contextual por tenant (empresa).
 */
class AuthController extends Controller
{
    /**
     * Muestra la vista de inicio de sesión.
     */
    public function showLoginForm(Request $request): View
    {
        // Marca blanca: si se entra por el subdominio o dominio propio de una
        // empresa se muestra su logo y nombre. Nunca se lista a las empresas.
        $tenant = $this->tenantFromHost($request->getHost());

        return view('auth.login', compact('tenant'));
    }

    /**
     * Procesa la solicitud de login.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'     => ['required', 'email'],
            'password'  => ['required', 'string'],
            'remember'  => ['nullable', 'boolean'],
        ]);

        $remember = $request->boolean('remember');

        // Búsqueda del usuario con sus credenciales
        $userQuery = User::where('email', $credentials['email'])
            ->where('status', 'active');

        // En el dominio de una empresa solo entran sus usuarios
        if ($hostTenant = $this->tenantFromHost($request->getHost())) {
            $userQuery->where('tenant_id', $hostTenant->id);
        }

        $user = $userQuery->first();

        // Mismo bloqueo por fuerza bruta que la API (LoginGuard)
        try {
            $user = app(LoginGuard::class)->attempt($request, $user, $hostTenant?->id ?? $user?->tenant_id);
        } catch (ValidationException $e) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => $e->status === 422
                    ? 'Las credenciales proporcionadas no son válidas o la cuenta está inactiva.'
                    : collect($e->errors())->flatten()->first()]);
        }

        // Validar que el rol tenga acceso al panel web
        if (! $user->isSuperAdmin() && ! $user->hasPermission('dashboard.view')) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Los empleados de campo deben registrarse a través de la aplicación móvil TuneSA.']);
        }

        app(LoginGuard::class)->registerSuccess($user, $request);

        // Iniciar sesión
        Auth::login($user, $remember);
        $request->session()->regenerate();

        // Guardar tenant_id en la sesión para aislamiento multi-tenant
        if ($user->tenant_id) {
            $request->session()->put('current_tenant_id', $user->tenant_id);
        }

        return redirect()->intended(route('dashboard'))
            ->with('success', "¡Bienvenido de nuevo, {$user->first_name}!");
    }

    /**
     * Empresa del subdominio (empresa.tunesa.app) o dominio propio, si aplica.
     */
    private function tenantFromHost(string $host): ?Tenant
    {
        $appDomain = config('app.domain', 'tunesa.app');

        if (str_ends_with($host, ".{$appDomain}")) {
            $slug = substr($host, 0, -strlen(".{$appDomain}"));

            return in_array($slug, ['www', 'api', 'admin', 'app', 'mail', 'cdn'], true)
                ? null
                : Tenant::where('slug', $slug)->first();
        }

        if ($host === $appDomain || in_array($host, ['localhost', '127.0.0.1'], true)) {
            return null;
        }

        return Tenant::where('custom_domain', strtolower($host))->first();
    }

    /**
     * Cierra la sesión activa.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->with('info', 'Has cerrado sesión exitosamente.');
    }
}
