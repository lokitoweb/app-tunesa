<?php

namespace App\Modules\Core\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\LoginGuard;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * AuthController (Web)
 *
 * Gestiona el acceso al panel web para administradores, RRHH y supervisores.
 * Seguridad reforzada: Anti-inyección, Anti-fuerza bruta, Honeypot y mensajes en español.
 */
class AuthController extends Controller
{
    /**
     * Muestra la vista de inicio de sesión.
     */
    public function showLoginForm(Request $request): View
    {
        $tenant = $this->tenantFromHost($request->getHost());

        return view('auth.login', compact('tenant'));
    }

    /**
     * Procesa la solicitud de login con validaciones de seguridad estrictas.
     */
    public function login(Request $request): RedirectResponse
    {
        // 1. Trampa Honeypot contra bots y escaneos automáticos
        if ($request->filled('_auth_token_verify')) {
            sleep(1);
            return redirect()->route('login')->withErrors(['email' => 'Acceso denegado por seguridad.']);
        }

        // 2. Sanitización contra inyecciones y caracteres nulos
        $rawEmail = (string) $request->input('email', '');
        $cleanEmail = trim(str_replace(["\0", "\r", "\n"], '', $rawEmail));
        $request->merge(['email' => $cleanEmail]);

        // 3. Validación estricta con mensajes en español
        $credentials = $request->validate([
            'email'     => ['required', 'string', 'email', 'max:180'],
            'password'  => ['required', 'string', 'max:128'],
            'remember'  => ['nullable', 'boolean'],
        ], [
            'email.required'    => 'El correo corporativo es obligatorio.',
            'email.email'       => 'Ingresa un formato de correo electrónico válido.',
            'email.max'         => 'El correo corporativo no puede exceder los 180 caracteres.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.max'      => 'La contraseña no puede exceder los 128 caracteres.',
        ]);

        $remember = $request->boolean('remember');

        // 4. Consulta parametrizada segura (Protección contra SQL Injection)
        $userQuery = User::where('email', $credentials['email'])
            ->where('status', 'active');

        // En el dominio de una empresa solo entran sus usuarios
        if ($hostTenant = $this->tenantFromHost($request->getHost())) {
            $userQuery->where('tenant_id', $hostTenant->id);
        }

        $user = $userQuery->first();

        // 5. Bloqueo por fuerza bruta (LoginGuard)
        try {
            $user = app(LoginGuard::class)->attempt($request, $user, $hostTenant?->id ?? $user?->tenant_id);
        } catch (ValidationException $e) {
            return redirect()->route('login')
                ->withInput($request->only('email'))
                ->withErrors(['email' => $e->status === 422
                    ? 'Las credenciales proporcionadas no son válidas o la cuenta está inactiva.'
                    : collect($e->errors())->flatten()->first()]);
        }

        // 6. Validar que el rol tenga acceso al panel web
        if (! $user->isSuperAdmin() && ! $user->hasPermission('dashboard.view')) {
            return redirect()->route('login')
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Los colaboradores de campo deben ingresar mediante la aplicación móvil '
                    . \App\Modules\Core\Models\TenantSetting::forTenant($user->tenant)->tradeName() . '.']);
        }

        app(LoginGuard::class)->registerSuccess($user, $request);

        // 7. Iniciar sesión y regenerar ID de sesión (Protección contra Session Fixation & Hijacking)
        Auth::login($user, $remember);
        $request->session()->regenerate();

        // Guardar tenant_id en la sesión para aislamiento multi-tenant
        if ($user->tenant_id) {
            $request->session()->put('current_tenant_id', $user->tenant_id);
        }

        // Evitar que un url.intended residual redirija a logout, login o acciones POST
        $intended = $request->session()->pull('url.intended', route('dashboard'));
        if (! $intended || str_contains($intended, '/logout') || str_contains($intended, '/login') || str_contains($intended, '/reveal') || str_contains($intended, '/_test')) {
            $intended = route('dashboard');
        }

        return redirect()->to($intended)
            ->with('success', "¡Bienvenido de nuevo, {$user->first_name}!");
    }

    /**
     * Cierra la sesión del usuario.
     */
    public function logout(Request $request): RedirectResponse
    {
        // Un GET (p. ej. un enlace externo) no cierra una sesión viva: solo el POST con CSRF
        if ($request->isMethod('get') && Auth::check()) {
            return redirect()->route('dashboard');
        }

        $wasExpired = $request->boolean('expired');
        $request->session()->forget('url.intended');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($wasExpired) {
            return redirect()->route('login')->with('info', 'Tu sesión se cerró por inactividad. Por favor, ingresa tus credenciales para continuar.');
        }

        return redirect()->route('login');
    }

    /**
     * Empresa del subdominio (empresa.tunesa.app) o dominio propio, si aplica.
     */
    private function tenantFromHost(string $host): ?Tenant
    {
        return Tenant::fromHost($host);
    }
}
