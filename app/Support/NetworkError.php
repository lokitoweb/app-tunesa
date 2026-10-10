<?php

namespace App\Support;

use Illuminate\Support\Str;
use Throwable;

/**
 * Traduce errores de red (cURL / Guzzle, en inglés y con detalles técnicos)
 * a un mensaje en español para el panel. El detalle original va al log.
 *
 *  NetworkError::describe($e)                → "Sin respuesta dentro del tiempo límite."
 *  NetworkError::humanize('cURL error 6: …') → "No se encontró el servidor (revisa el dominio de la URL)."
 */
final class NetworkError
{
    public static function describe(Throwable $e): string
    {
        return self::fromMessage($e->getMessage());
    }

    /** Mensaje técnico → mensaje para el usuario (o el mismo texto si ya es legible) */
    public static function humanize(?string $message): ?string
    {
        if ($message === null || $message === '' || ! self::isTechnical($message)) {
            return $message;
        }

        return self::fromMessage($message);
    }

    private static function isTechnical(string $message): bool
    {
        return (bool) preg_match('/cURL error|libcurl|GuzzleHttp|Operation timed out|Could not resolve host|Connection refused|CONNECT tunnel|SSL certificate|SSL routines/i', $message);
    }

    private static function fromMessage(string $message): string
    {
        $m = Str::lower($message);

        return match (true) {
            str_contains($m, 'timed out')                     => 'Sin respuesta dentro del tiempo límite.',
            str_contains($m, 'could not resolve host')        => 'No se encontró el servidor (revisa el dominio de la URL).',
            str_contains($m, 'connection refused')            => 'El servidor rechazó la conexión.',
            str_contains($m, 'connect tunnel'), str_contains($m, 'proxy') => 'La red del servidor bloqueó la conexión (proxy o firewall). Pide a soporte que habilite la salida a ese dominio.',
            str_contains($m, 'ssl'), str_contains($m, 'certificate') => 'No se pudo verificar el certificado de seguridad (HTTPS) del destino. Si el certificado es válido, actualiza los certificados raíz del servidor (curl.cainfo en php.ini).',
            str_contains($m, 'connection reset'), str_contains($m, 'empty reply') => 'El servidor cortó la conexión antes de responder.',
            default                                           => 'No se pudo conectar con el servidor de destino.',
        };
    }
}
