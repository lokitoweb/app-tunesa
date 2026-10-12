<?php

namespace App\Modules\Core\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/**
 * PasswordResetController (Web)
 *
 * "¿Olvidaste tu contraseña?" del panel. El correo de recuperación
 * (AppServiceProvider → ResetPassword::createUrlUsing) apunta a
 * APP_URL/reset-password?token=...&email=...&tenant=...
 *
 * Solo para quienes usan el panel: a los colaboradores de la app les
 * restablece el acceso RRHH ("Restablecer acceso" en su ficha), por política
 * de las tiendas y de la empresa. Al terminar se vuelve al login.
 */
class PasswordResetController extends Controller
{
    public const SUCCESS_MESSAGE = 'Contraseña restablecida. Ya puedes ingresar al panel.';

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

        // Solo usuarios del panel; al resto se les responde igual, sin enviar nada
        if (self::usesPanel(User::where('email', $request->input('email'))->first())) {
            Password::sendResetLink($request->only('email'));
        }

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

        $status = self::usesPanel(User::where('email', $request->input('email'))->first())
            ? Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                fn (User $user, string $password) => $user->completePasswordReset($password)
            )
            : Password::INVALID_USER;

        if ($status !== Password::PASSWORD_RESET) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'El enlace de restablecimiento no es válido o expiró. Solicita uno nuevo.']);
        }

        return redirect()->route('login')->with('status', self::SUCCESS_MESSAGE);
    }

    /** Super Admin, administradores, RRHH y supervisores (los que entran al panel) */
    public static function usesPanel(?User $user): bool
    {
        return $user !== null && $user->hasPermission('dashboard.view');
    }
}
