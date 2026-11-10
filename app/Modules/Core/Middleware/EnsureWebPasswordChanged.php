<?php

namespace App\Modules\Core\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel web y Mi espacio: quien entró con una contraseña temporal (la inicial con su DNI, la de
 * "Olvidé mi contraseña" o la que dio RRHH al restablecer el acceso) la cambia antes de hacer
 * cualquier otra cosa, igual que en la app (EnsurePasswordChanged). Vale para todo: pantallas,
 * envíos de formularios y pedidos JSON, así una sesión con la contraseña temporal no puede
 * saltarse el cambio. Solo quedan libres el cambio de contraseña, el cierre de sesión, la sesión
 * misma y las páginas legales.
 */
class EnsureWebPasswordChanged
{
    private const ALLOWED_ROUTES = [
        'account.edit', 'account.password', 'portal.profile', 'portal.password',
        'logout', 'csrf.refresh', 'session.keep-alive', 'legal.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->must_change_password || $request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        $message = 'Estás usando una contraseña temporal: crea una nueva para continuar.';
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['success' => false, 'message' => $message, 'code' => 'PASSWORD_CHANGE_REQUIRED'], 403);
        }

        // El panel tiene Mi cuenta; quien solo usa Mi espacio cambia la contraseña en su perfil
        $target = $user->isSuperAdmin() || $user->hasPermission('dashboard.view') ? 'account.edit' : 'portal.profile';

        return redirect()->route($target)->with('info', $message);
    }
}
