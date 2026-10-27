<?php

namespace Tests\Feature;

use App\Modules\Attendance\Jobs\ReverseGeocodePunch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Integrations\Services\Geocoding\GeocodingException;
use App\Modules\Integrations\Services\Geocoding\GeocodingService;
use App\Modules\Integrations\Services\Geocoding\NominatimGeocoder;
use App\Modules\Core\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** GeocodingService (Nominatim / Google), caché, endpoints y job de la bitácora */
class GeocodingTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const NOMINATIM_REVERSE = ['display_name' => 'Av. Javier Prado Este 2578, San Isidro, Lima, Perú'];

    private const NOMINATIM_SEARCH = [
        ['display_name' => 'Larcomar, Miraflores, Lima', 'lat' => '-12.1316', 'lon' => '-77.0303'],
        ['display_name' => 'Parque Kennedy, Miraflores', 'lat' => '-12.1211', 'lon' => '-77.0297'],
    ];

    private const GOOGLE_REVERSE = ['status' => 'OK', 'results' => [['formatted_address' => 'Av. Javier Prado Este 2578, San Isidro 15036, Perú',
        'geometry' => ['location' => ['lat' => -12.09418, 'lng' => -77.0356]]]]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        config(['app.url' => 'https://panel.tunesa.test', 'app.name' => 'Mi ERP']);
    }

    private function service(?Tenant $tenant = null): GeocodingService
    {
        return app(GeocodingService::class)->forTenant($tenant);
    }

    public function test_nominatim_reverse_and_search_with_policy_headers(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/reverse*' => Http::response(self::NOMINATIM_REVERSE),
            'nominatim.openstreetmap.org/search*'  => Http::response(self::NOMINATIM_SEARCH),
        ]);
        config(['services.nominatim.countrycodes' => 'pe']);

        $this->assertSame(
            ['address' => 'Av. Javier Prado Este 2578, San Isidro, Lima, Perú', 'provider' => 'nominatim'],
            $this->service()->reverse(-12.094181234, -77.035601234),
        );

        $this->assertSame([
            ['label' => 'Larcomar, Miraflores, Lima', 'lat' => -12.1316, 'lng' => -77.0303],
            ['label' => 'Parque Kennedy, Miraflores', 'lat' => -12.1211, 'lng' => -77.0297],
        ], $this->service()->search('larcomar', 2));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/reverse')
            && $r->header('User-Agent')[0] === 'Mi-ERP/1.0 (+https://panel.tunesa.test)'
            && $r['accept-language'] === 'es'
            && $r['lat'] == -12.09418 && $r['lon'] == -77.0356);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/search')
            && $r['q'] === 'larcomar' && $r['limit'] == 2 && $r['countrycodes'] === 'pe' && $r['accept-language'] === 'es');

        $this->assertSame('Mi-ERP/1.0 (+https://panel.tunesa.test)', NominatimGeocoder::userAgent());
    }

    public function test_results_are_cached_reverse_by_rounded_coordinates(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/reverse*' => fn (Request $r) => (float) $r['lat'] === 10.0
                ? Http::response(['error' => 'Unable to geocode'])
                : Http::response(self::NOMINATIM_REVERSE),
            'nominatim.openstreetmap.org/search*'  => Http::response(self::NOMINATIM_SEARCH),
        ]);

        $this->service()->reverse(-12.094181, -77.035601);
        $this->service()->reverse(-12.094184, -77.035598); // misma celda de 5 decimales
        $this->service()->search('Larcomar');
        $this->service()->search('  larcomar ');
        Http::assertSentCount(2);

        $this->service()->reverse(-12.09430, -77.03560); // otra celda
        Http::assertSentCount(3);

        // "Sin resultado" no se cachea
        $this->assertSame(['address' => null, 'provider' => 'nominatim'], $this->service()->reverse(10.0, 10.0));
        $this->service()->reverse(10.0, 10.0);
        Http::assertSentCount(5);
    }

    public function test_nominatim_is_limited_to_one_request_per_interval(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(self::NOMINATIM_REVERSE)]);
        config(['services.nominatim.min_interval_ms' => 300]);

        $start = microtime(true);
        $this->service()->reverse(1.0, 1.0);
        $this->service()->reverse(2.0, 2.0);
        $this->service()->reverse(3.0, 3.0);

        $this->assertGreaterThanOrEqual(0.58, microtime(true) - $start);
    }

    public function test_google_is_used_only_with_a_key_and_falls_back_to_nominatim(): void
    {
        Http::fake([
            'maps.googleapis.com/*'       => Http::response(self::GOOGLE_REVERSE),
            'nominatim.openstreetmap.org/*' => Http::response(self::NOMINATIM_REVERSE),
        ]);
        $acme = $this->makeTenant('acme');

        // google elegido sin clave → Nominatim
        TenantSetting::editableRowFor($acme)->fill(['geocoding_provider' => 'google'])->save();
        $this->assertSame('nominatim', $this->service($acme)->reverse(-12.09418, -77.0356)['provider']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'googleapis'));

        // clave heredada de la plataforma (google_maps_api_key = maps_api_key)
        TenantSetting::editableRowFor(null)->fill(['maps_api_key' => 'AIzaPlataforma'])->save();
        $this->assertSame(
            ['address' => 'Av. Javier Prado Este 2578, San Isidro 15036, Perú', 'provider' => 'google'],
            $this->service($acme)->reverse(-12.09418, -77.0356),
        );
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'maps.googleapis.com/maps/api/geocode/json')
            && isset($r->data()['latlng']) && $r['key'] === 'AIzaPlataforma' && $r['latlng'] === '-12.0941800,-77.0356000' && $r['language'] === 'es');

        $results = $this->service($acme)->search('San Isidro');
        $this->assertSame([['label' => 'Av. Javier Prado Este 2578, San Isidro 15036, Perú', 'lat' => -12.09418, 'lng' => -77.0356]], $results);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'googleapis') && ($r->data()['address'] ?? null) === 'San Isidro');
    }

    public function test_google_errors_fall_back_to_nominatim(): void
    {
        Http::fake([
            'maps.googleapis.com/*'       => Http::response(['status' => 'REQUEST_DENIED', 'error_message' => 'API key invalid']),
            'nominatim.openstreetmap.org/*' => Http::response(self::NOMINATIM_REVERSE),
        ]);
        $acme = $this->makeTenant('acme');
        TenantSetting::editableRowFor($acme)->fill(['geocoding_provider' => 'google', 'maps_api_key' => 'AIzaMala'])->save();

        $this->assertSame('nominatim', $this->service($acme)->reverse(-12.1, -77.0)['provider']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'googleapis'));
    }

    public function test_nominatim_failure_is_an_exception(): void
    {
        Http::fake(['*' => Http::response('caído', 503)]);
        $this->expectException(GeocodingException::class);
        $this->service()->reverse(-12.2, -77.1);
    }

    public function test_endpoint_answers_503_when_the_provider_is_down(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        $admin = $this->makeUser($this->makeTenant('acme'), 'company_admin');

        $this->actingAs($admin)->getJson('/geocode/search?q=otra cosa')
            ->assertStatus(503)->assertJsonPath('code', 'GEOCODING_UNAVAILABLE')->assertJsonPath('success', false);
    }

    public function test_web_and_api_endpoints(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/reverse*' => Http::response(self::NOMINATIM_REVERSE),
            'nominatim.openstreetmap.org/search*'  => Http::response(self::NOMINATIM_SEARCH),
            'maps.googleapis.com/*'                => Http::response(self::GOOGLE_REVERSE),
        ]);
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        $emp   = $this->makeUser($acme);

        $this->actingAs($admin)->getJson('/geocode/search?q=larcomar')
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.0.label', 'Larcomar, Miraflores, Lima')
            ->assertJsonPath('data.0.lat', -12.1316);
        $this->actingAs($admin)->getJson('/geocode/search?q=ab')->assertStatus(422);
        $this->actingAs($admin)->getJson('/geocode/reverse?lat=-12.09418&lng=-77.0356')
            ->assertOk()->assertJsonPath('data', ['address' => self::NOMINATIM_REVERSE['display_name'], 'provider' => 'nominatim']);
        $this->actingAs($admin)->getJson('/geocode/reverse?lat=99&lng=0')->assertStatus(422);

        app(TenantContext::class)->clear();
        $this->actingIn($emp, $acme)->getJson('/api/geocode/reverse?lat=-12.09418&lng=-77.0356')
            ->assertOk()->assertJsonPath('data.provider', 'nominatim');

        // Super Admin en la vista global: proveedor y clave de la plataforma
        app(TenantContext::class)->clear();
        TenantSetting::editableRowFor(null)->fill(['geocoding_provider' => 'google', 'maps_api_key' => 'AIzaGlobal'])->save();
        $root = $this->makeUser(null, 'super_admin');
        $this->actingAs($root)->getJson('/geocode/reverse?lat=-12.5&lng=-77.5')
            ->assertOk()->assertJsonPath('data.provider', 'google');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'googleapis') && $r['key'] === 'AIzaGlobal');
    }

    public function test_reverse_geocode_job_tolerates_missing_punches_and_provider_failures(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        // Un fallo del proveedor con la cola sync solo queda en el log
        (new ReverseGeocodePunch(999_999))->handle(app(GeocodingService::class));
        $this->assertTrue(true);
    }

    public function test_reverse_addresses_are_short_and_skip_plus_codes(): void
    {
        $this->assertSame('Av. Javier Prado Este 2578, San Isidro, Lima', \App\Modules\Integrations\Services\Geocoding\NominatimGeocoder::compact([
            'road' => 'Av. Javier Prado Este', 'house_number' => '2578', 'suburb' => 'San Isidro', 'city' => 'Lima',
            'state' => 'Lima', 'postcode' => '15036', 'country' => 'Perú',
        ]));
        $this->assertNull(\App\Modules\Integrations\Services\Geocoding\NominatimGeocoder::compact(['country' => 'Perú']));

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['status' => 'OK', 'results' => [
            ['formatted_address' => '8Q7X+2C Lima', 'types' => ['plus_code']],
            ['formatted_address' => 'Av. Arequipa 1200, Lince 15046, Perú', 'types' => ['street_address']],
        ]])]);
        $this->assertSame('Av. Arequipa 1200, Lince 15046, Perú', (new \App\Modules\Integrations\Services\Geocoding\GoogleGeocoder('k'))->reverse(-12.08, -77.03));
    }
}
