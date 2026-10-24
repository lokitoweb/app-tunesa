<?php

namespace App\Modules\Core\Support;

/**
 * Dirección pública del servidor (APP_URL) y limpieza de direcciones viejas.
 *
 * Con túneles de prueba (*.trycloudflare.com) el dominio cambia en cada
 * arranque. Lo que quedó guardado con el túnel anterior (la "Dirección del
 * servidor para la app" de Marca y app, o el receptor de webhooks precargado
 * en Integraciones) apuntaría a un destino muerto. Aquí se corrige:
 *  - el receptor propio de webhooks siempre se arma con APP_URL al enviar;
 *  - un túnel guardado que no es el actual no se anuncia a la app: se anuncia
 *    APP_URL.
 */
final class PlatformUrl
{
    /** Ruta del receptor propio de webhooks (routes: integrations.receiver) */
    public const RECEIVER_PATH = '#^/api/v1/webhooks/receiver(/[a-z0-9-]+)?/?$#';

    /** Dominios de túneles temporales: cambian en cada arranque */
    public const TUNNEL_SUFFIXES = ['.trycloudflare.com', '.ngrok-free.app', '.ngrok.io', '.loca.lt'];

    /** APP_URL sin barra final */
    public static function current(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public static function host(?string $url): ?string
    {
        $host = $url ? parse_url(trim($url), PHP_URL_HOST) : null;

        return $host ? strtolower($host) : null;
    }

    public static function isTunnel(?string $url): bool
    {
        $host = self::host($url);

        return $host !== null && collect(self::TUNNEL_SUFFIXES)->contains(fn ($s) => str_ends_with($host, $s));
    }

    /** Túnel que ya no es el dominio de APP_URL */
    public static function isStaleTunnel(?string $url): bool
    {
        return self::isTunnel($url) && self::host($url) !== self::host(self::current());
    }

    /** ¿Es la URL del receptor de webhooks de esta misma plataforma (en cualquier dominio)? */
    public static function isOwnReceiver(?string $url): bool
    {
        $path = $url ? parse_url(trim($url), PHP_URL_PATH) : null;

        return is_string($path) && preg_match(self::RECEIVER_PATH, $path) === 1;
    }

    /**
     * Destino real de un webhook: el receptor propio se arma con APP_URL
     * (conserva ruta y consulta); cualquier otra URL se respeta tal cual.
     */
    public static function webhookTarget(string $url): string
    {
        if (! self::isOwnReceiver($url) || self::host($url) === self::host(self::current())) {
            return $url;
        }

        $parts = parse_url(trim($url));

        return self::current() . $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * Dominio que se anuncia a la app (mobile.api_base_url):
     *  - una URL guardada (p. ej. un túnel de pruebas) se respeta;
     *  - un túnel viejo guardado, o el campo vacío, usan el dominio del
     *    servidor (APP_URL) si es https: la app sigue al dominio actual sin
     *    escribirlo a mano. Sin https no se anuncia nada.
     */
    public static function apiBaseUrl(?string $stored): ?string
    {
        $stored = $stored ? rtrim(trim($stored), '/') : null;

        if ($stored && ! self::isStaleTunnel($stored)) {
            return $stored;
        }

        return self::detected();
    }

    /** Dominio del servidor que se usa por defecto (APP_URL), solo si es https */
    public static function detected(): ?string
    {
        $current = self::current();

        return str_starts_with($current, 'https://') ? $current : null;
    }
}
