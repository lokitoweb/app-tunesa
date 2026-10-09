<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware: EnforceTenantScope
 *
 * Segunda capa de seguridad multi-tenant.
 * Se aplica DESPUÉS de ResolveTenant y verifica que el usuario autenticado
 * pertenece al mismo tenant de la request.
 *
 * Previene ataques donde un usuario de Tenant A intente acceder a datos
 * de Tenant B enviando una cabecera X-Tenant-Slug diferente.
 *
 * Pipeline de middlewares en rutas API protegidas:
 *   api → [tenant, auth:sanctum, tenant.scope] → Controller
 */
class EnforceTenantScope
{
    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Super Admin puede acceder a cualquier tenant (acceso cross-tenant)
        if ($user && $user->isSuperAdmin()) {
            return $next($request);
        }

        // Si el usuario no tiene tenant asignado y no es super_admin
        if ($user && is_null($user->tenant_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Tu cuenta no está asociada a ninguna empresa.',
                'code'    => 'USER_NO_TENANT',
            ], 403);
        }

        // Verificar que el tenant del usuario coincide con el tenant resuelto
        if ($user && $this->tenantContext->resolved()) {
            $resolvedTenantId = $this->tenantContext->id();

            if ($user->tenant_id !== $resolvedTenantId) {
                // Log del intento de acceso cross-tenant
                logger()->warning('Intento de acceso cross-tenant detectado', [
                    'user_id'            => $user->id,
                    'user_tenant_id'     => $user->tenant_id,
                    'requested_tenant_id'=> $resolvedTenantId,
                    'ip'                 => $request->ip(),
                    'url'                => $request->fullUrl(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'No tienes autorización para acceder a este recurso.',
                    'code'    => 'TENANT_SCOPE_VIOLATION',
                ], 403);
            }
        }

        return $next($request);
    }
}
