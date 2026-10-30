<?php

namespace App\Modules\Core\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel web: quien entró con una contraseña temporal (olvidó su contraseña o RRHH le restableció
 * el acceso) la cambia antes de seguir, igual que en la app (EnsurePasswordChanged). Mientras
 * tanto, cualquier pantalla del panel lo lleva a Mi cuenta. Solo pantallas (GET): los envíos
 * y las rutas de la propia cuenta, el cierre de sesión y la sesión siguen libres.
 * Mi espacio (portal) no tiene cambio de contraseña: el colaborador la cambia en la app.
 */
class EnsureWebPasswordChanged
{
    private const ALLOWED_ROUTES = ['account.edit', 'account.password', 'logout', 'csrf.refresh', 'session.keep-alive'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->must_change_password
            && $request->isMethod('GET') && ! $request->expectsJson()
            && ! $request->routeIs(...self::ALLOWED_ROUTES)
            && ! $request->routeIs('portal.*')
            && ($user->isSuperAdmin() || $user->hasPermission('dashboard.view'))) {
            return redirect()->route('account.edit')
                ->with('info', 'Estás usando una contraseña temporal: crea una nueva para continuar.');
        }

        return $next($request);
    }
}
