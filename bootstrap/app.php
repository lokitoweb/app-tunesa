<?php

/**
 * =============================================================================
 * bootstrap/app.php — Configuración de la aplicación Laravel 11+
 *
 * Registra middlewares, providers y bindings del sistema multi-tenant.
 * =============================================================================
 */

use App\Modules\Core\Middleware\CheckPermission;
use App\Modules\Core\Middleware\CheckRole;
use App\Modules\Core\Middleware\EnforceTenantScope;
use App\Modules\Core\Middleware\EnsurePasswordChanged;
use App\Modules\Core\Middleware\EnsureSupportedAppVersion;
use App\Modules\Core\Middleware\HandleInertiaRequests;
use App\Modules\Core\Middleware\RequestReference;
use App\Modules\Core\Middleware\ResolveTenant;
use App\Modules\Core\Middleware\SecurityHeaders;
use App\Modules\Core\Middleware\SetWebTenant;
use App\Modules\Onboarding\Middleware\EnsureOnboardingApproved;
use App\Modules\Ethics\Middleware\StripIdentity;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Las rutas web y API viven en app/Modules/<Módulo>/Routes (ModuleServiceProvider)
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
            'app.version'  => EnsureSupportedAppVersion::class,
            'onboarding.approved' => EnsureOnboardingApproved::class,
            'ethics.anonymous' => StripIdentity::class,
        ]);

        // =====================================================================
        // PROXIES DE CONFIANZA (Cloudflare Tunnel, Nginx, balanceadores)
        // cloudflared entrega la petición desde 127.0.0.1 con X-Forwarded-Proto=https:
        // confiar en él hace que Laravel genere URLs https (selfies firmadas,
        // assets) y vea la IP real del usuario. TRUSTED_PROXIES='*' para PaaS.
        // La lista sale de config('app.trusted_proxies') en AppServiceProvider::boot():
        // aquí .env no está cargado cuando la config está en caché (config:cache).
        // =====================================================================

        // =====================================================================
        // GRUPO DE MIDDLEWARE 'api'
        // Aplicado automáticamente a todas las rutas en routes/api.php
        // =====================================================================
        // Panel web: datos compartidos y protocolo de las pantallas Vue (Inertia)
        // y quien entra con contraseña temporal la cambia antes de seguir (como en la app)
        $middleware->web(append: [HandleInertiaRequests::class, \App\Modules\Core\Middleware\EnsureWebPasswordChanged::class]);

        // Sin instalar, todo va al asistente /install (y /install se cierra al terminar)
        $middleware->prepend(\App\Installer\Http\RedirectToInstaller::class);

        // Código de referencia por petición (logs + páginas de error) y depuración solo en local
        // Texto que no es UTF-8 válido: 400 antes de tocar cualquier dato
        $middleware->prepend(\App\Modules\Core\Middleware\CleanUtf8Input::class);

        $middleware->prepend(RequestReference::class);

        // Cabeceras de seguridad en toda respuesta (panel, API y errores)
        $middleware->append(SecurityHeaders::class);

        // Cadena de toda ruta autenticada de la app móvil (ver Core/Routes/api.php)
        $middleware->group('api.tenant', ['tenant', 'app.version', 'auth:sanctum', 'tenant.scope', 'password.fresh']);

        $middleware->group('api', [
            \Illuminate\Routing\Middleware\ThrottleRequests::class . ':api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {

        // Nunca flashear campos sensibles a la sesión
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
        ]);

        // Ciberseguridad: censurar parámetros confidenciales de la petición antes de cualquier volcado o pantalla de error
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            $sensitiveKeys = ['password', 'password_confirmation', 'current_password', 'secret', 'token', 'access_token', 'refresh_token', 'credit_card', 'cvv'];
            foreach ($sensitiveKeys as $key) {
                if ($request->has($key)) {
                    $request->request->set($key, '********');
                    $request->query->set($key, '********');
                }
            }
            return null; // Continuar con la cadena habitual de excepciones
        });

        // 419 en el panel web: el token CSRF del formulario caducó (la página quedó
        // abierta más que la sesión, o se cerró por inactividad). En vez de una
        // pantalla de error se vuelve al formulario con un aviso, y en el login se
        // conserva el correo: basta volver a escribir la contraseña. El POST
        // rechazado no se ejecuta, así que la protección CSRF sigue intacta.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e, \Illuminate\Http\Request $request) {
            if (! $e->getPrevious() instanceof \Illuminate\Session\TokenMismatchException || $request->is('api', 'api/*')) {
                return null;
            }

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json([
                    'success'    => false,
                    'message'    => 'La página estuvo inactiva y su código de seguridad caducó. Vuelve a intentarlo.',
                    'code'       => 'CSRF_EXPIRED',
                    'csrf_token' => $request->hasSession() ? csrf_token() : null,
                ], 419);
            }

            if (! $request->hasSession()) {
                return null;
            }

            // Volver a la página anterior solo si es de este mismo sitio (el Referer
            // lo controla quien envía el POST: sin esto sería un redirect abierto)
            $back = function (string $fallback) use ($request): string {
                $previous = url()->previous($fallback);
                $sameHost = parse_url($previous, PHP_URL_HOST) === $request->getHost();
                $samePost = rtrim((string) parse_url($previous, PHP_URL_PATH), '/') === rtrim('/' . ltrim($request->path(), '/'), '/');

                return $sameHost && ! $samePost ? $previous : $fallback;
            };

            $safeInput = $request->except(['password', 'password_confirmation', 'current_password', '_token', '_auth_token_verify']);

            if (! \Illuminate\Support\Facades\Auth::check()) {
                $message = $request->routeIs('login.post')
                    ? 'La página de acceso estuvo abierta mucho tiempo y, por seguridad, se renovó. Ingresa tu contraseña otra vez.'
                    : 'Tu sesión se cerró por inactividad. Ingresa tus credenciales para continuar.';

                $target = $request->routeIs('password.email', 'password.update') ? $back(route('login')) : route('login');

                return redirect()->to($target, 303)
                    ->withInput($request->routeIs('login.post') ? $request->only('email') : $safeInput)
                    ->with('info', $message);
            }

            return redirect()->to($back(route('dashboard')), 303)
                ->withInput($safeInput)
                ->with('info', 'El formulario estuvo abierto mucho tiempo y su código de seguridad caducó. Revisa los datos y vuelve a enviarlo.');
        });

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

        // Laravel convierte AuthorizationException en AccessDeniedHttpException
        // (prepareException) ANTES de llamar a estos renderers: se atiende la
        // excepción convertida y se mira la original en getPrevious().
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, $request) {
            if ($request->is('api/*') && $e->getPrevious() instanceof \Illuminate\Auth\Access\AuthorizationException) {
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

        // Igual que arriba: ModelNotFoundException llega como NotFoundHttpException
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, $request) {
            if ($request->is('api/*') && $e->getPrevious() instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return response()->json([
                    'success' => false,
                    'message' => 'El recurso solicitado no fue encontrado.',
                    'code'    => 'NOT_FOUND',
                ], 404);
            }
        });

        // Resto de errores HTTP 4xx en la API (ruta inexistente, método no
        // permitido, límite de peticiones...): siempre JSON limpio, también con
        // APP_DEBUG=true, en vez de la página de excepción de Laravel.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e, $request) {
            $status = $e->getStatusCode();
            if (! $request->is('api', 'api/*') || $status >= 500) {
                return null;
            }

            [$code, $message] = match ($status) {
                404     => ['NOT_FOUND', 'La ruta solicitada no existe.'],
                405     => ['METHOD_NOT_ALLOWED', 'Método no permitido para esta ruta.'],
                429     => ['TOO_MANY_REQUESTS', 'Demasiadas solicitudes. Intenta de nuevo en un momento.'],
                403     => ['FORBIDDEN', 'No tienes permiso para esta acción.'],
                default => ['HTTP_ERROR', 'No se pudo procesar la solicitud.'],
            };

            return response()->json([
                'success' => false,
                // abort(403, 'mensaje') conserva su texto; el genérico cubre el resto
                'message' => $e->getMessage() !== '' && ! in_array($status, [404, 405, 429], true) ? $e->getMessage() : $message,
                'code'    => $code,
            ], $status, $e->getHeaders());
        });

        // Errores inesperados (5xx) en la API: en producción nunca se exponen el
        // mensaje de la excepción, SQL, rutas ni IPs internas. Va al final para
        // que los renderers de arriba atiendan primero sus casos.
        $exceptions->render(function (\Throwable $e, $request) {
            if (! $request->is('api/*') || config('app.debug')) {
                return null;
            }
            if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException
                || $e instanceof \Illuminate\Auth\AuthenticationException
                || $e instanceof \Illuminate\Validation\ValidationException) {
                return null;
            }
            $status = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $e->getStatusCode() : 500;
            if ($status < 500) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error inesperado. Intenta de nuevo en unos minutos.',
                'code'    => 'SERVER_ERROR',
            ], $status);
        });

    })
    ->create();
