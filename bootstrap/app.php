<?php

/**
 * =============================================================================
 * bootstrap/app.php — Configuración de la aplicación Laravel 11+
 *
 * Registra middlewares, providers y bindings del sistema multi-tenant.
 * =============================================================================
 */

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnforceTenantScope;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetWebTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        // =====================================================================
        // ALIASES DE MIDDLEWARE
        // Permiten usar nombre corto en las rutas: ->middleware('tenant')
        // =====================================================================
        $middleware->alias([
            'tenant'       => ResolveTenant::class,
            'tenant.scope' => EnforceTenantScope::class,
            'role'         => CheckRole::class,
            'permission'   => CheckPermission::class,
            'web.tenant'   => SetWebTenant::class,
            'password.fresh' => EnsurePasswordChanged::class,
        ]);

        // =====================================================================
        // GRUPO DE MIDDLEWARE 'api'
        // Aplicado automáticamente a todas las rutas en routes/api.php
        // =====================================================================
        $middleware->group('api', [
            \Illuminate\Routing\Middleware\ThrottleRequests::class . ':api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {

        // Personalizar respuestas JSON para excepciones comunes
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No autenticado. Por favor inicia sesión.',
                    'code'    => 'UNAUTHENTICATED',
                ], 401);
            }
        });

        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para esta acción.',
                    'code'    => 'UNAUTHORIZED',
                ], 403);
            }
        });

        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, $request) {
            if ($request->is('api/*')) {
                // 423 = cuenta bloqueada, 429 = demasiados intentos (LoginGuard)
                return response()->json([
                    'success' => false,
                    'message' => $e->status === 422 ? 'Error de validación.' : collect($e->errors())->flatten()->first(),
                    'code'    => match ($e->status) {
                        423     => 'ACCOUNT_LOCKED',
                        429     => 'TOO_MANY_ATTEMPTS',
                        default => 'VALIDATION_ERROR',
                    },
                    'errors'  => $e->errors(),
                ], $e->status);
            }
        });

        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'El recurso solicitado no fue encontrado.',
                    'code'    => 'NOT_FOUND',
                ], 404);
            }
        });

    })
    ->create();
