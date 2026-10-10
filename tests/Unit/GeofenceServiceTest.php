<?php

namespace Tests\Unit;

use App\Modules\Attendance\Services\GeofenceService;
use PHPUnit\Framework\TestCase;

/**
 * GeofenceServiceTest
 *
 * Prueba la fórmula Haversine con coordenadas GPS reales y conocidas.
 * Estas pruebas son deterministas: no requieren base de datos ni mocks.
 *
 * Referencia de distancias calculadas con Google Maps para validación:
 * - Lima (Sede): -12.09418, -77.03560
 * - Punto 30m al norte de Sede: -12.09391, -77.03560  ≈ 30m
 * - Punto 200m al este de Sede: -12.09418, -77.03381  ≈ 200m
 * - Miraflores: -18.0546, -70.2518  → muy lejos
 */
class GeofenceServiceTest extends TestCase
{
    private GeofenceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GeofenceService();
    }

    // =========================================================================
    // TESTS DE DISTANCIA (Haversine)
    // =========================================================================

    /** @test */
    public function it_calculates_zero_distance_for_same_point(): void
    {
        $distance = $this->service->calculateDistance(
            lat1: -12.09418,
            lng1: -77.03560,
            lat2: -12.09418,
            lng2: -77.03560,
        );

        $this->assertEquals(0.0, $distance, 'Distancia al mismo punto debe ser 0');
    }

    /** @test */
    public function it_calculates_approximately_30_meters_northward(): void
    {
        // Mover ~30m al norte: 1 grado lat ≈ 111,320m → 0.0003° ≈ 33.4m
        $distance = $this->service->calculateDistance(
            lat1: -12.09418,
            lng1: -77.03560,
            lat2: -12.09391, // ~30m al norte
            lng2: -77.03560,
        );

        // Tolerancia de ±5 metros por redondeo
        $this->assertEqualsWithDelta(30.0, $distance, 5.0,
            "Distancia al norte debería ser ~30m, calculó: {$distance}m"
        );
    }

    /** @test */
    public function it_calculates_approximately_200_meters_eastward(): void
    {
        $distance = $this->service->calculateDistance(
            lat1: -12.09418,
            lng1: -77.03560,
            lat2: -12.09418,
            lng2: -77.03381, // ~200m al este (en Lima, aprox 0.001797°/111m = 0.0016°)
        );

        $this->assertEqualsWithDelta(200.0, $distance, 10.0,
            "Distancia al este debería ser ~200m, calculó: {$distance}m"
        );
    }

    /** @test */
    public function it_calculates_real_world_distance_lima_to_miraflores(): void
    {
        // Lima (San Isidro) a Miraflores → ~6.6 km en línea recta
        $distance = $this->service->calculateDistance(
            lat1: -12.09418,
            lng1: -77.03560,
            lat2: -18.0546,
            lng2: -70.2518,
        );

        // Verificamos que no es 0 y es mayor a 100km (están a gran distancia)
        $this->assertGreaterThan(100_000, $distance,
            'La distancia entre Lima y Tacna debe ser mayor a 100km'
        );
    }

    /** @test */
    public function it_is_symmetric(): void
    {
        // La distancia de A→B debe ser igual a B→A
        $d1 = $this->service->calculateDistance(-12.09418, -77.03560, -12.09391, -77.03380);
        $d2 = $this->service->calculateDistance(-12.09391, -77.03380, -12.09418, -77.03560);

        $this->assertEqualsWithDelta($d1, $d2, 0.001, 'Haversine debe ser simétrica');
    }

    // =========================================================================
    // TESTS DE BOUNDING BOX
    // =========================================================================

    /** @test */
    public function it_generates_correct_bounding_box(): void
    {
        $lat    = -12.09418;
        $lng    = -77.03560;
        $radius = 100; // 100 metros

        $box = $this->service->getBoundingBox($lat, $lng, $radius);

        $this->assertArrayHasKey('min_lat', $box);
        $this->assertArrayHasKey('max_lat', $box);
        $this->assertArrayHasKey('min_lng', $box);
        $this->assertArrayHasKey('max_lng', $box);

        $this->assertLessThan($lat, $box['min_lat']);
        $this->assertGreaterThan($lat, $box['max_lat']);
        $this->assertLessThan($lng, $box['min_lng']);
        $this->assertGreaterThan($lng, $box['max_lng']);
    }

    // =========================================================================
    // TESTS DE VALIDACIÓN DE COORDENADAS
    // =========================================================================

    /** @test */
    public function it_validates_correct_coordinates(): void
    {
        $this->assertTrue($this->service->areValidCoordinates(-12.09418, -77.03560));
        $this->assertTrue($this->service->areValidCoordinates(40.7128, -74.0060)); // New York
        $this->assertTrue($this->service->areValidCoordinates(-33.8688, 151.2093));  // Sydney
    }

    /** @test */
    public function it_rejects_invalid_coordinates(): void
    {
        $this->assertFalse($this->service->areValidCoordinates(0.0, 0.0));       // Mar de Guinea
        $this->assertFalse($this->service->areValidCoordinates(91.0, 0.0));      // Lat > 90
        $this->assertFalse($this->service->areValidCoordinates(0.0, 181.0));     // Lng > 180
        $this->assertFalse($this->service->areValidCoordinates(-91.0, 0.0));     // Lat < -90
    }

    // =========================================================================
    // TESTS DE FORMATO
    // =========================================================================

    /** @test */
    public function it_formats_distances_correctly(): void
    {
        $this->assertEquals('47 m',   $this->service->formatDistance(47.4));
        $this->assertEquals('100 m',  $this->service->formatDistance(100.0));
        $this->assertEquals('1.5 km', $this->service->formatDistance(1500.0));
        $this->assertEquals('2.3 km', $this->service->formatDistance(2300.0));
    }

    // =========================================================================
    // TESTS DE GeofenceResult DTO
    // =========================================================================

    /** @test */
    public function it_returns_correct_message_when_inside(): void
    {
        $result = new \App\Modules\Attendance\Services\GeofenceResult(
            isWithinGeofence: true,
            distanceMeters:   35,
            allowedRadius:    100,
            branchName:       'Sede Principal',
            excessMeters:     0,
        );

        $this->assertTrue($result->isWithinGeofence);
        $this->assertStringContainsString('35m', $result->getMessage());
        $this->assertStringContainsString('Sede Principal', $result->getMessage());
    }

    /** @test */
    public function it_returns_correct_message_when_outside(): void
    {
        $result = new \App\Modules\Attendance\Services\GeofenceResult(
            isWithinGeofence: false,
            distanceMeters:   250,
            allowedRadius:    50,
            branchName:       'Sucursal Norte',
            excessMeters:     200,
        );

        $this->assertFalse($result->isWithinGeofence);
        $msg = $result->getMessage();
        $this->assertStringContainsString('250m', $msg);
        $this->assertStringContainsString('50m', $msg);
        $this->assertStringContainsString('200m', $msg);
    }
}
