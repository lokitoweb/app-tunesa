<?php

namespace App\Modules\Core\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Código de referencia de cada petición (p. ej. "7F3A9C21").
 *
 * Se guarda en el contexto de todo log y se muestra en las páginas de error:
 * si un usuario reporta "Ref: 7F3A9C21", basta buscarlo en storage/logs para
 * ver qué falló. Va también en la cabecera X-Request-Id.
 *
 * Además, la página de depuración de Laravel (APP_DEBUG=true) solo se muestra
 * a quien entra directo desde la propia máquina (127.0.0.1 / localhost). Por el
 * túnel de Cloudflare o un proxy nunca: esa página expone consultas SQL, el id
 * de sesión y datos de la petición.
 */
class RequestReference
{
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    private const PROXY_HEADERS = ['X-Forwarded-For', 'X-Forwarded-Host', 'Forwarded', 'CF-Connecting-IP', 'CF-Ray'];

    public function handle(Request $request, Closure $next): Response
    {
        $ref = strtoupper(bin2hex(random_bytes(4)));
        $request->attributes->set('ref', $ref);
        Log::shareContext(['ref' => $ref]);

        if (config('app.debug') && ! $this->isDirectLocalRequest($request)) {
            config(['app.debug' => false]);
        }

        $response = $next($request);
        $response->headers->set('X-Request-Id', $ref);

        return $response;
    }

    private function isDirectLocalRequest(Request $request): bool
    {
        foreach (self::PROXY_HEADERS as $header) {
            if ($request->headers->has($header)) {
                return false;
            }
        }

        return in_array(strtolower($request->getHost()), self::LOOPBACK_HOSTS, true)
            && in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true);
    }
}
