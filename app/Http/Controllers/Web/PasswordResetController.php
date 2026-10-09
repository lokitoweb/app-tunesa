<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/**
 * PasswordResetController (Web)
 *
 * "¿Olvidaste tu contraseña?" desde el navegador. El correo de recuperación
 * (AppServiceProvider → ResetPassword::createUrlUsing) apunta a
 * APP_URL/reset-password?token=...&email=...&tenant=..., y lo reciben tanto
 * usuarios del panel como colaboradores de la app (que lo piden con
 * POST /api/auth/forgot-password). Por eso al terminar no se inicia sesión:
 * se vuelve al login con un mensaje que sirve para ambos.
 */
class PasswordResetController extends Controller
{
    public const SUCCESS_MESSAGE = 'Contraseña restablecida. Ya puedes ingresar en la app o en el panel.';

    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    /** GET /forgot-password */
    public function showRequestForm(Request $request): View
    {
        return view('auth.forgot-password', ['tenant' => Tenant::fromHost($request->getHost())]);
    }

    /** POST /forgot-password */
    public function sendLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']], [
            'email.required' => 'Ingresa tu correo electrónico.',
            'email.email'    => 'Ingresa un correo electrónico válido.',
        ]);

        // En el dominio de una empresa solo se buscan sus usuarios (TenantScope)
        if ($tenant = Tenant::fromHost($request->getHost())) {
            $this->tenantContext->set($tenant);
        }

        Password::sendResetLink($request->only('email'));

        // Mismo mensaje exista o no el correo: no se revela quién está registrado
        return back()->with('status', 'Si el correo está registrado, recibirás un enlace para restablecer tu contraseña. Revisa también la carpeta de spam.');
    }

    /** GET /reset-password/{token} o /reset-password?token=...&email=... (enlace del correo) */
    public function showResetForm(Request $request, ?string $token = null): View|RedirectResponse
    {
        $token ??= $request->query('token');

        if (! is_string($token) || $token === '') {
            return redirect()->route('password.request')
                ->withErrors(['email' => 'El enlace de restablecimiento no es válido. Solicita uno nuevo.']);
        }

        $tenant = $request->filled('tenant') && is_string($request->query('tenant'))
            ? Tenant::where('slug', $request->query('tenant'))->first()
            : Tenant::fromHost($request->getHost());

        return view('auth.reset-password', [
            'token'  => $token,
            'email'  => (string) $request->query('email', ''),
            'tenant' => $tenant,
        ]);
    }

    /** POST /reset-password */
    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'confirmed', User::resetPasswordRule()],
        ], [
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ], [
            'password' => 'contraseña',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            fn (User $user, string $password) => $user->completePasswordReset($password)
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'El enlace de restablecimiento no es válido o expiró. Solicita uno nuevo.']);
        }

        return redirect()->route('login')->with('status', self::SUCCESS_MESSAGE);
    }
}
