<?php

namespace App\Modules\Integrations\Services;

use App\Modules\Integrations\Models\WebhookDelivery;
use App\Modules\Core\Support\NetworkError;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envío HTTP firmado a una integración de la empresa y registro del intento en
 * `webhook_deliveries`. Lo usan el job SendTenantWebhook (eventos reales, con
 * reintentos) y los botones de prueba de "Marca y app › Integraciones".
 *
 *   POST <url>
 *   Content-Type: application/json
 *   X-Tunesa-Event: <evento>
 *   X-Tunesa-Signature: sha256=<hex HMAC-SHA256 del cuerpo con webhook_secret>
 *
 * Nunca lanza excepciones: devuelve el resultado y, si falla el registro en
 * la base, solo deja un aviso en el log (el envío no depende de la trazabilidad).
 */
class WebhookSender
{
    public const REQUEST_LIMIT  = 16384;
    public const RESPONSE_LIMIT = 4096;

    public static function sign(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    /** Crea la fila "pending" de un envío; null si no se pudo guardar */
    public function open(?int $tenantId, string $target, string $event, string $url, string $body, ?string $uuid = null): ?WebhookDelivery
    {
        try {
            return WebhookDelivery::firstOrCreate(['uuid' => $uuid ?? (string) Str::uuid()], [
                'tenant_id'    => $tenantId,
                'target'       => $target,
                'event'        => $event,
                'url'          => Str::limit($url, 250, ''),
                'request_body' => self::truncate($body, self::REQUEST_LIMIT),
                'attempt'      => 0,
                'status'       => WebhookDelivery::STATUS_PENDING,
            ]);
        } catch (Throwable $e) {
            Log::warning('No se pudo registrar el envío a la integración: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Hace un intento y lo registra en $delivery.
     *
     * @param  int|null  $retryIn  segundos hasta el próximo intento si este falla
     *                             (null = no habrá otro: el fallo es definitivo)
     * @return array{ok: bool, status: int|null, ms: int, body?: string, error?: string, signature: string|null}
     */
    public function send(
        string $url,
        string $event,
        string $body,
        ?string $secret,
        ?WebhookDelivery $delivery = null,
        int $attempt = 1,
        ?int $retryIn = null,
        ?int $timeout = null,
    ): array {
        $timeout   = $timeout ?? (int) config('integrations.timeout', 10);
        $signature = filled($secret) ? 'sha256=' . self::sign($body, $secret) : null;
        $headers   = ['X-Tunesa-Event' => $event] + ($signature ? ['X-Tunesa-Signature' => $signature] : []);

        $start  = hrtime(true);
        $status = null;
        $answer = null;
        $error  = null;

        try {
            if ($reason = $this->blockedReason($url)) {
                $error = $reason;
            } else {
                $response = Http::timeout($timeout)
                    ->connectTimeout(min($timeout, 5))
                    ->withOptions(self::requestOptions() + self::pinnedResolve($url))
                    ->withHeaders($headers)
                    ->withBody($body, 'application/json')
                    ->post($url);

                $status = $response->status();
                $answer = self::truncate($response->body(), self::RESPONSE_LIMIT);
                if (! $response->successful()) {
                    $error = "El destino respondió HTTP {$status}.";
                }
            }
        } catch (Throwable $e) {
            $error = self::connectionError($e);
        }

        $ms = (int) round((hrtime(true) - $start) / 1e6);
        $ok = $error === null;

        if ($delivery) {
            $this->record($delivery, [
                'attempt'       => $attempt,
                'signature'     => $signature,
                'status'        => $ok ? WebhookDelivery::STATUS_SUCCESS : ($retryIn ? WebhookDelivery::STATUS_PENDING : WebhookDelivery::STATUS_FAILED),
                'http_status'   => $status,
                'response_ms'   => $ms,
                'response_body' => $answer,
                'error'         => $error,
                'next_retry_at' => ! $ok && $retryIn ? now()->addSeconds($retryIn) : null,
            ]);
        }

        return ['ok' => $ok, 'status' => $status, 'ms' => $ms, 'signature' => $signature]
            + ($ok ? ['body' => (string) $answer] : ['error' => $error, 'body' => $answer]);
    }

    /** Marca como fallido un envío que quedó pendiente (p. ej. el job agotó su tiempo) */
    public function fail(?WebhookDelivery $delivery, string $error): void
    {
        if ($delivery && $delivery->status === WebhookDelivery::STATUS_PENDING) {
            $this->record($delivery, ['status' => WebhookDelivery::STATUS_FAILED, 'error' => $error, 'next_retry_at' => null]);
        }
    }

    /**
     * Motivo para no enviar a la URL, o null si se permite. Se rechazan las
     * direcciones privadas, locales o reservadas (salvo INTEGRATIONS_ALLOW_PRIVATE_URLS
     * o el host de la propia plataforma, APP_URL).
     */
    public function blockedReason(string $url): ?string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host   = trim((string) parse_url($url, PHP_URL_HOST), '[]');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'La URL debe empezar con http:// o https://.';
        }
        if (config('integrations.allow_private_urls')) {
            return null;
        }
        // El receptor de la propia plataforma (URL precargada) siempre se permite:
        // mismo host que APP_URL y solo la ruta del receptor
        $receiverPath = (string) parse_url(route('api.integrations.receiver'), PHP_URL_PATH);
        if (strcasecmp($host, (string) parse_url((string) config('app.url'), PHP_URL_HOST)) === 0
            && str_starts_with((string) parse_url($url, PHP_URL_PATH), $receiverPath)) {
            return null;
        }

        // IPv4 e IPv6: un dominio con solo AAAA (p. ej. ::1 o fd00::/8) ya no pasa sin revisar
        $ips = self::resolve($host);
        if (! $ips && app()->isProduction()) {
            // Sin IP no hay qué revisar ni qué fijar (rebinding): igual no se podría entregar
            return 'No se pudo resolver el dominio de la URL. Revisa que esté bien escrito.';
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return 'La URL apunta a una red privada o local; usa una dirección pública.';
            }
        }

        return null;
    }

    /**
     * IP pública (ni privada, ni local, ni reservada). Una IPv6 que envuelve una IPv4
     * (::ffff:127.0.0.1, ::127.0.0.1) se revisa como esa IPv4: PHP la deja pasar y curl
     * se conectaría a la IPv4 de adentro.
     */
    private static function isPublicIp(string $ip): bool
    {
        $binary = @inet_pton($ip);
        if ($binary !== false && strlen($binary) === 16) {
            $prefix = substr($binary, 0, 12);
            if ($prefix === str_repeat("\0", 10) . "\xff\xff" || $prefix === str_repeat("\0", 12)) {
                $ip = (string) inet_ntop(substr($binary, 12));
            }
        }

        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /** Direcciones IPv4 e IPv6 del host (o el propio host si ya es una IP) */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        if (strtolower($host) === 'localhost') {
            return ['127.0.0.1', '::1'];
        }
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }
        $ips = array_values(array_unique(array_filter($ips)));

        return $ips ?: (@gethostbynamel($host) ?: []);
    }

    /**
     * Fija la conexión a una IP ya revisada en blockedReason(): un DNS que cambia entre la revisión
     * y el envío (rebinding) no lleva la petición a una red interna. Sin IP (o si el host ya es una
     * IP, o se permiten redes privadas) no hay nada que fijar.
     */
    private static function pinnedResolve(string $url): array
    {
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) || config('integrations.allow_private_urls') || ! defined('CURLOPT_RESOLVE')) {
            return [];
        }
        $ip = self::resolve($host)[0] ?? null;
        if (! $ip) {
            return [];
        }
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80));

        return ['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:" . (str_contains($ip, ':') ? "[{$ip}]" : $ip)]]];
    }

    /**
     * ¿Se verifica el certificado HTTPS del destino? Siempre en producción;
     * fuera de ella solo si INTEGRATIONS_VERIFY_SSL=true (config/integrations.php).
     */
    public static function verifiesSsl(): bool
    {
        return app()->isProduction() || (bool) config('integrations.verify_ssl', false);
    }

    /**
     * Opciones de Guzzle de cada envío. Una redirección podría llevar a una red
     * interna: no se siguen. Certificado HTTPS: ver verifiesSsl().
     */
    public static function requestOptions(): array
    {
        return ['allow_redirects' => false, 'verify' => self::verifiesSsl()];
    }

    public static function truncate(?string $text, int $bytes): ?string
    {
        if ($text === null) {
            return null;
        }
        // Las respuestas pueden traer bytes no UTF-8: se reemplazan para poder guardarlas y mostrarlas
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        return strlen($text) > $bytes ? mb_strcut($text, 0, $bytes, 'UTF-8') . '…' : $text;
    }

    private static function connectionError(Throwable $e): string
    {
        Log::info('Error de red al enviar a la integración: ' . $e->getMessage());

        return NetworkError::describe($e);
    }

    private function record(WebhookDelivery $delivery, array $values): void
    {
        try {
            $delivery->fill($values)->save();
        } catch (Throwable $e) {
            Log::warning('No se pudo registrar el intento de envío a la integración: ' . $e->getMessage());
        }
    }
}
