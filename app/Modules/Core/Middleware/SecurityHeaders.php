<?php

namespace App\Modules\Core\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad de toda respuesta (panel web, API y páginas de error):
 *  - no se puede incrustar en un iframe de otro sitio (clickjacking),
 *  - el navegador no adivina tipos de archivo,
 *  - no se filtra la URL completa a sitios externos,
 *  - el panel no usa cámara ni micrófono del navegador,
 *  - con HTTPS, el navegador recuerda usar siempre HTTPS.
 *
 * Las respuestas que ya fijan una cabecera (p. ej. SecureFileController) la conservan.
 */
class SecurityHeaders
{
    private const HEADERS = [
        'X-Frame-Options'        => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=(self)',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }
        if ($request->isSecure() && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
