<?php

namespace App\Modules\Integrations\Services\Geocoding;

use App\Modules\Attendance\Services\GeofenceService;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Services\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
 * Reverse dentro del radio permitido de una sede de la empresa: la dirección
 * registrada de esa sede, sin llamar a ningún proveedor (branchAddressAt).
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

    /** Motivo del último rechazo de Google Map Tiles (para la prueba de Integraciones) */
    private ?string $tilesError = null;

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
        return TenantSetting::forTenant($this->tenant());
    }

    private function tenant(): ?Tenant
    {
        return $this->tenantFixed ? $this->tenant : $this->context->getOrNull();
    }

    /**
     * Dirección de la sede de la empresa en cuyo radio permitido cae el punto (la más cercana),
     * tomada de la base de datos: así una marcación en la sede no consulta a Google ni a Nominatim.
     */
    public function branchAddressAt(float $lat, float $lng): ?string
    {
        if (! $tenant = $this->tenant()) {
            return null;
        }
        $geofence = app(GeofenceService::class);

        $nearest = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->whereNotNull('latitude')->whereNotNull('longitude')->where('address', '!=', '')->whereNotNull('address')
            ->get(['id', 'address', 'latitude', 'longitude', 'allowed_radius_meters'])
            ->map(fn (Branch $b) => [$b, $geofence->calculateDistance($lat, $lng, (float) $b->latitude, (float) $b->longitude)])
            ->filter(fn ($pair) => $pair[1] <= (int) $pair[0]->allowed_radius_meters)
            ->sortBy(fn ($pair) => $pair[1])
            ->first();

        return $nearest ? $nearest[0]->address : null;
    }

    /**
     * Capas de Google (Map Tiles API) para los mapas del panel cuando el proveedor efectivo es Google;
     * null = capas gratuitas (OpenStreetMap / Esri). La sesión de Google dura ~2 semanas y se cachea;
     * si Google la rechaza (API no habilitada, clave inválida) se reintenta en 10 minutos.
     *
     * @return array{streets: string, satellite: string, attribution: string}|null
     */
    public function mapTiles(): ?array
    {
        $settings = $this->settings();
        if ($settings->geocodingProvider() !== 'google') {
            return null;
        }
        $key = (string) $settings->googleMapsApiKey();

        $session = function (string $type) use ($key): string {
            $cacheKey = 'maptiles:' . $type . ':' . sha1($key);
            if (is_string($cached = Cache::get($cacheKey))) {
                return $cached;
            }
            try {
                $res = Http::timeout(8)->post('https://tile.googleapis.com/v1/createSession?key=' . urlencode($key), [
                    'mapType' => $type, 'language' => 'es-419', 'region' => 'PE',
                ]);
                if ($res->successful() && is_string($res->json('session'))) {
                    $expiry = (int) $res->json('expiry');
                    Cache::put($cacheKey, $res->json('session'), $expiry > time() + 86400 ? now()->setTimestamp($expiry)->subDay() : now()->addHour());

                    return $res->json('session');
                }
                $this->tilesError = 'Google Map Tiles: HTTP ' . $res->status() . (is_string($res->json('error.message')) ? ' (' . $res->json('error.message') . ')' : '');
                Log::warning($this->tilesError);
            } catch (\Throwable $e) {
                $this->tilesError = 'Google Map Tiles no respondió: ' . $e->getMessage();
                Log::warning($this->tilesError);
            }
            Cache::put($cacheKey, '', now()->addMinutes(10));

            return '';
        };

        $streets   = $session('roadmap');
        $satellite = $session('satellite');
        if ($streets === '' || $satellite === '') {
            return null;
        }
        $url = fn (string $s) => 'https://tile.googleapis.com/v1/2dtiles/{z}/{x}/{y}?session=' . $s . '&key=' . urlencode($key);

        return ['streets' => $url($streets), 'satellite' => $url($satellite), 'attribution' => '&copy; Google'];
    }

    /**
     * Prueba directa de Google para Integraciones (sin respaldo a Nominatim ni caché): dirección del
     * punto con la Geocoding API y sesión de capas con la Map Tiles API, con el motivo si Google rechaza.
     *
     * @return array{google: bool, geocoding?: array{ok: bool, address?: ?string, error?: string}, tiles?: array{ok: bool, error?: string}}
     */
    public function googleDiagnostics(float $lat, float $lng): array
    {
        $settings = $this->settings();
        if ($settings->geocodingProvider() !== 'google') {
            return ['google' => false];
        }
        $key = (string) $settings->googleMapsApiKey();

        try {
            $geocoding = ['ok' => true, 'address' => (new GoogleGeocoder($key))->reverse($lat, $lng)];
        } catch (GeocodingException $e) {
            $geocoding = ['ok' => false, 'error' => $e->getMessage()];
        }

        foreach (['roadmap', 'satellite'] as $type) {
            Cache::forget('maptiles:' . $type . ':' . sha1($key));
        }
        $this->tilesError = null;
        $tiles = $this->mapTiles() ? ['ok' => true] : ['ok' => false, 'error' => $this->tilesError ?? 'Google Map Tiles no devolvió sesión.'];

        return ['google' => true, 'geocoding' => $geocoding, 'tiles' => $tiles];
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

        $result = $this->withFallback($geocoder, fn (Geocoder $g) => $g->search($query, $limit));

        // Respuesta de respaldo (Google falló): solo 1 hora, para volver a Google en cuanto se corrija
        Cache::put($key, $result['value'], $result['provider'] === $geocoder->name() ? now()->addDays(self::SEARCH_TTL_DAYS) : now()->addHour());

        return $result['value'];
    }

    /**
     * @return array{address: ?string, provider: string}
     *
     * @throws GeocodingException
     */
    public function reverse(float $lat, float $lng): array
    {
        // Dentro del radio de una sede: su dirección registrada, sin llamar al proveedor (ni costo de Google)
        if ($branchAddress = $this->branchAddressAt($lat, $lng)) {
            return ['address' => $branchAddress, 'provider' => 'branch'];
        }

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
            Cache::put($key, $data, $data['provider'] === $geocoder->name() ? now()->addDays(self::REVERSE_TTL_DAYS) : now()->addHour());
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
