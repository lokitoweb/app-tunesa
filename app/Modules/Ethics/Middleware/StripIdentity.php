<?php

namespace App\Modules\Ethics\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rutas del denunciante: se quitan del request la IP, el user agent, los
 * encabezados de proxy y cualquier token de sesión, para que nada de la
 * petición pueda terminar en la base, la auditoría o los logs de la app.
 * El límite de peticiones se aplica aquí mismo, antes de borrar la IP: la clave
 * es un hash de la IP que vive solo en la caché y vence en un minuto.
 */
class StripIdentity
{
    private const HEADERS = [
        'User-Agent', 'Authorization', 'Cookie', 'X-Forwarded-For', 'X-Real-IP', 'Forwarded',
        'CF-Connecting-IP', 'True-Client-IP', 'X-Device-Integrity', 'X-Device-Id', 'X-Device-Model', 'X-Os-Version',
    ];

    public function handle(Request $request, Closure $next, string $perMinute = '30'): Response
    {
        $key = 'ethics:' . $request->route()?->uri() . ':' . hash('sha256', $request->ip() . config('app.key'));
        if (RateLimiter::tooManyAttempts($key, (int) $perMinute)) {
            return response()->json([
                'success' => false,
                'message' => 'Demasiados intentos. Espera un minuto y vuelve a intentarlo.',
                'code'    => 'TOO_MANY_ATTEMPTS',
            ], 429);
        }
        RateLimiter::hit($key, 60);

        foreach (self::HEADERS as $header) {
            $request->headers->remove($header);
        }
        $request->server->set('REMOTE_ADDR', '0.0.0.0');
        $request->server->remove('HTTP_USER_AGENT');
        $request->server->remove('HTTP_X_FORWARDED_FOR');
        $request->setUserResolver(fn () => null);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
