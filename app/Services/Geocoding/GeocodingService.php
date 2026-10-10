<?php

namespace App\Services\Geocoding;

use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Geocodificación (búsqueda de direcciones y dirección de un punto GPS).
 *
 * Proveedor: el `geocoding_provider` efectivo de la empresa (empresa →
 * plataforma → "nominatim"). Google solo si está elegido Y hay
 * google_maps_api_key (columna maps_api_key); si no, Nominatim. Si Google
 * falla se reintenta con Nominatim.
 *
 * Empresa: la del contexto (TenantContext) salvo que se use forTenant(); sin
 * empresa (vista global del Super Admin, consola) se usa la fila de la plataforma.
 *
 * Caché (store por defecto): reverse 30 días por coordenadas redondeadas a 5
 * decimales (~1 m); search 1 día. No se cachean errores ni respuestas vacías
 * de reverse.
 */
class GeocodingService
{
    public const REVERSE_TTL_DAYS = 30;

    public const SEARCH_TTL_DAYS = 1;

    private bool $tenantFixed = false;

    private ?Tenant $tenant = null;

    public function __construct(private readonly TenantContext $context) {}

    /** Copia ligada a una empresa (null = plataforma), p. ej. desde un job sin contexto */
    public function forTenant(?Tenant $tenant): self
    {
        $clone              = clone $this;
        $clone->tenant      = $tenant;
        $clone->tenantFixed = true;

        return $clone;
    }

    public function settings(): TenantSetting
    {
        return TenantSetting::forTenant($this->tenantFixed ? $this->tenant : $this->context->getOrNull());
    }

    /** Proveedor principal resuelto para la empresa */
    public function geocoder(): Geocoder
    {
        $settings = $this->settings();

        return $settings->geocodingProvider() === 'google'
            ? new GoogleGeocoder($settings->googleMapsApiKey())
            : new NominatimGeocoder();
    }

    /**
     * @return list<array{label: string, lat: float, lng: float}>
     *
     * @throws GeocodingException
     */
    public function search(string $query, int $limit = 5): array
    {
        $query = trim(preg_replace('/\s+/', ' ', $query));
        $limit = max(1, min(10, $limit));

        if ($query === '') {
            return [];
        }

        $geocoder = $this->geocoder();
        $key      = sprintf('geocode:search:%s:%d:%s', $geocoder->name(), $limit, sha1(mb_strtolower($query)));

        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $results = $this->withFallback($geocoder, fn (Geocoder $g) => $g->search($query, $limit))['value'];

        Cache::put($key, $results, now()->addDays(self::SEARCH_TTL_DAYS));

        return $results;
    }

    /**
     * @return array{address: ?string, provider: string}
     *
     * @throws GeocodingException
     */
    public function reverse(float $lat, float $lng): array
    {
        $lat = round($lat, 5);
        $lng = round($lng, 5);

        $geocoder = $this->geocoder();
        $key      = sprintf('geocode:reverse:%s:%.5F:%.5F', $geocoder->name(), $lat, $lng);

        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $result = $this->withFallback($geocoder, fn (Geocoder $g) => $g->reverse($lat, $lng));
        $data   = ['address' => $result['value'], 'provider' => $result['provider']];

        if ($data['address'] !== null) {
            Cache::put($key, $data, now()->addDays(self::REVERSE_TTL_DAYS));
        }

        return $data;
    }

    /** @return array{value: mixed, provider: string} */
    private function withFallback(Geocoder $geocoder, callable $call): array
    {
        try {
            return ['value' => $call($geocoder), 'provider' => $geocoder->name()];
        } catch (GeocodingException $e) {
            if ($geocoder instanceof NominatimGeocoder) {
                throw $e;
            }

            Log::warning('Google Geocoding falló; se usa Nominatim: ' . $e->getMessage());
            $fallback = new NominatimGeocoder();

            return ['value' => $call($fallback), 'provider' => $fallback->name()];
        }
    }
}
