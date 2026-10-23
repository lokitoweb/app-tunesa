<?php

namespace App\Modules\Core\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rechaza (400) peticiones con texto que no es UTF-8 válido, antes de que
 * llegue a cualquier controlador. Sin esto, un byte suelto (p. ej. "é" en
 * Latin-1) se guarda en la base y luego cada respuesta JSON que lo incluye
 * falla con 500 (pasó con las justificaciones de asistencia).
 *
 * Se rechaza en vez de "reparar": adivinar la codificación original podría
 * guardar un texto distinto al que se quiso enviar. La app y el panel
 * siempre envían UTF-8. Los archivos subidos no se revisan (son binarios).
 */
class CleanUtf8Input
{
    public function handle(Request $request, Closure $next): Response
    {
        $field = $this->firstInvalid($request->query->all()) ?? $this->firstInvalid($request->request->all());

        if ($field !== null) {
            $message = 'El texto enviado tiene caracteres no válidos. Vuelve a escribirlo e inténtalo de nuevo.';

            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => $message, 'code' => 'INVALID_ENCODING', 'field' => $field], 400)
                : response($message, 400, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return $next($request);
    }

    /** Nombre del primer campo (en notación con puntos) con UTF-8 inválido */
    private function firstInvalid(array $input, string $prefix = ''): ?string
    {
        foreach ($input as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (! mb_check_encoding((string) $key, 'UTF-8')) {
                return '(nombre de campo)';
            }
            if (is_array($value) && ($found = $this->firstInvalid($value, $path)) !== null) {
                return $found;
            }
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                return mb_check_encoding($path, 'UTF-8') ? $path : '(nombre de campo)';
            }
        }

        return null;
    }
}
