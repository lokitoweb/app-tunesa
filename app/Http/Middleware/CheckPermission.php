<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware: CheckPermission
 *
 * Verifica que el usuario tenga AL MENOS UNO de los permisos indicados
 * (tabla roles ↔ permissions sembrada por RolesAndPermissionsSeeder).
 *
 * Uso en rutas:
 *   ->middleware('permission:shifts.manage')
 *   ->middleware('permission:leave.approve_team,leave.approve_all')
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'No autenticado.',
                'code'    => 'UNAUTHENTICATED',
            ], 401);
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        return response()->json([
            'success'              => false,
            'message'              => 'No tienes permisos suficientes para esta acción.',
            'code'                 => 'INSUFFICIENT_PERMISSION',
            'required_permissions' => $permissions,
        ], 403);
    }
}
