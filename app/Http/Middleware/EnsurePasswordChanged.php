<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cuentas creadas por RRHH nacen con contraseña temporal
 * (users.must_change_password). Hasta cambiarla solo pueden consultar su
 * perfil, cambiar la contraseña o cerrar sesión.
 */
class EnsurePasswordChanged
{
    private const ALLOWED = ['api/auth/me', 'api/auth/change-password', 'api/auth/logout', 'api/auth/logout-all'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->must_change_password && ! $request->is(...self::ALLOWED)) {
            return response()->json([
                'success' => false,
                'message' => 'Debes cambiar tu contraseña temporal antes de continuar.',
                'code'    => 'PASSWORD_CHANGE_REQUIRED',
            ], 403);
        }

        return $next($request);
    }
}
