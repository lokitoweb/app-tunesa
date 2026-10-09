<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware: CheckRole
 *
 * Verifica que el usuario autenticado tenga uno de los roles permitidos.
 *
 * Uso en rutas:
 *   ->middleware('role:company_admin,hr_manager')
 *   ->middleware('role:supervisor')
 *
 * El super_admin siempre tiene acceso sin restricción de rol.
 */
class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'No autenticado.',
                'code'    => 'UNAUTHENTICATED',
            ], 401);
        }

        // Super admin siempre pasa
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        // Verificar que el rol del usuario está en la lista de roles permitidos
        if (! in_array($user->role, $roles)) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos suficientes para esta acción.',
                'code'    => 'INSUFFICIENT_ROLE',
                'required_roles' => $roles,
                'your_role'      => $user->role,
            ], 403);
        }

        return $next($request);
    }
}
