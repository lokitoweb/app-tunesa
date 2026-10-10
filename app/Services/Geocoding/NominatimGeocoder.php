<?php

namespace App\Services\Geocoding;

use Illuminate\Contracts\Cache\LockTimeoutException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Nominatim (OpenStreetMap). Respeta la política de uso:
 *  - User-Agent identificable: "TuneSA/1.0 (+APP_URL)".
 *  - Máximo 1 petición por segundo para TODA la instalación: un candado de
 *    caché serializa las peticiones y espera lo que falte desde la anterior
 *    (services.nominatim.min_interval_ms, 1000 por defecto).
 *  - Resultados en español (accept-language=es) y, si se configuró,
 *    limitados a países (NOMINATIM_COUNTRYCODES).
 */
class NominatimGeocoder implements Geocoder
{
    private const LOCK_KEY = 'geocode:nominatim:lock';

    private const LAST_KEY = 'geocode:nominatim:last_request';

    public function name(): string
    {
        return 'nominatim';
    }

    public function search(string $query, int $limit = 5): array
    {
        $params = array_filter([
            'q'               => $query,
            'format'          => 'jsonv2',
            'limit'           => max(1, min(10, $limit)),
            'addressdetails'  => 0,
            'accept-language' => 'es',
            'countrycodes'    => config('services.nominatim.countrycodes'),
            'email'           => config('services.nominatim.email'),
        ], fn ($v) => $v !== null && $v !== '');

        $json = $this->get('/search', $params);

        if (! is_array($json) || (isset($json['error']) && ! array_is_list($json))) {
            throw new GeocodingException('Nominatim devolvió una respuesta inesperada.');
        }

        return array_values(array_map(fn (array $row) => [
            'label' => (string) ($row['display_name'] ?? ''),
            'lat'   => (float) $row['lat'],
            'lng'   => (float) $row['lon'],
        ], array_filter($json, fn ($row) => is_array($row) && isset($row['lat'], $row['lon']))));
    }

    public function reverse(float $lat, float $lng): ?string
    {
        $json = $this->get('/reverse', array_filter([
            'lat'             => $lat,
            'lon'             => $lng,
            'format'          => 'jsonv2',
            'zoom'            => 18,
            'addressdetails'  => 0,
            'accept-language' => 'es',
            'email'           => config('services.nominatim.email'),
        ], fn ($v) => $v !== null && $v !== ''));

        // {"error": "Unable to geocode"} = sin resultado (no es una falla)
        if (! is_array($json) || isset($json['error'])) {
            return null;
        }

        $address = trim((string) ($json['display_name'] ?? ''));

        return $address !== '' ? $address : null;
    }

    public static function userAgent(): string
    {
        return 'TuneSA/1.0 (+' . rtrim((string) config('app.url'), '/') . ')';
    }

    private function get(string $path, array $params): mixed
    {
        $this->throttle();

        try {
            $response = $this->client()->get(rtrim((string) config('services.nominatim.url'), '/') . $path, $params);
        } catch (ConnectionException|TransferException $e) {
            // TransferException: errores de red que Laravel no envuelve (proxy, TLS)
            throw new GeocodingException('No se pudo conectar con Nominatim: ' . $e->getMessage(), 0, $e);
        }

        if ($response->status() === 429) {
            throw new GeocodingException('Nominatim limitó las consultas (429). Intenta en unos segundos.');
        }

        if (! $response->successful()) {
            throw new GeocodingException("Nominatim respondió HTTP {$response->status()}.");
        }

        return $response->json();
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent'      => self::userAgent(),
            'Accept-Language' => 'es',
        ])->acceptJson()->timeout((int) config('services.nominatim.timeout', 8));
    }

    /** 1 petición por segundo en toda la instalación (candado + hora de la última petición en caché) */
    private function throttle(): void
    {
        $intervalMs = (int) config('services.nominatim.min_interval_ms', 1000);
        if ($intervalMs <= 0) {
            return;
        }

        try {
            Cache::lock(self::LOCK_KEY, 10)->block(10, function () use ($intervalMs) {
                $last = (float) Cache::get(self::LAST_KEY, 0);
                $wait = ($last + $intervalMs / 1000) - microtime(true);

                if ($wait > 0) {
                    usleep((int) ceil($wait * 1_000_000));
                }

                Cache::put(self::LAST_KEY, microtime(true), 60);
            });
        } catch (LockTimeoutException $e) {
            throw new GeocodingException('Demasiadas consultas de geocodificación en curso. Intenta de nuevo.', 0, $e);
        }
    }
}
