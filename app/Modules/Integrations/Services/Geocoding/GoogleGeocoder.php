<?php

namespace App\Modules\Integrations\Services\Geocoding;

use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Google Geocoding API (https://maps.googleapis.com/maps/api/geocode/json).
 * Usa la clave google_maps_api_key de Marca y app (columna maps_api_key).
 */
class GoogleGeocoder implements Geocoder
{
    public function __construct(private readonly string $apiKey) {}

    public function name(): string
    {
        return 'google';
    }

    public function search(string $query, int $limit = 5): array
    {
        $results = $this->get(['address' => $query]);

        return array_slice(array_values(array_map(fn (array $r) => [
            'label' => (string) ($r['formatted_address'] ?? ''),
            'lat'   => (float) $r['geometry']['location']['lat'],
            'lng'   => (float) $r['geometry']['location']['lng'],
        ], array_filter($results, fn ($r) => isset($r['geometry']['location']['lat'], $r['geometry']['location']['lng'])))), 0, max(1, min(10, $limit)));
    }

    public function reverse(float $lat, float $lng): ?string
    {
        $results = $this->get(['latlng' => sprintf('%.7F,%.7F', $lat, $lng)]);
        $address = trim((string) ($results[0]['formatted_address'] ?? ''));

        return $address !== '' ? $address : null;
    }

    /** @return list<array> results[] (vacío con ZERO_RESULTS) */
    private function get(array $params): array
    {
        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.google_geocoding.timeout', 8))
                ->get((string) config('services.google_geocoding.url'), $params + ['key' => $this->apiKey, 'language' => 'es']);
        } catch (ConnectionException|TransferException $e) {
            // TransferException: errores de red que Laravel no envuelve (proxy, TLS)
            throw new GeocodingException('No se pudo conectar con Google Geocoding: ' . $e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new GeocodingException("Google Geocoding respondió HTTP {$response->status()}.");
        }

        $status = (string) $response->json('status');

        if ($status === 'ZERO_RESULTS') {
            return [];
        }

        if ($status !== 'OK') {
            $detail = $response->json('error_message');

            throw new GeocodingException("Google Geocoding: {$status}" . ($detail ? " ({$detail})" : ''));
        }

        return (array) $response->json('results', []);
    }
}
