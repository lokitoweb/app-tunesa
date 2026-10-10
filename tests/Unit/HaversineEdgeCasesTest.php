<?php

namespace Tests\Unit;

use App\Services\GeofenceService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Casos límite de Haversine (R = 6 371 000 m): ecuador, cruce del
 * antimeridiano y precisión de 1 metro.
 */
class HaversineEdgeCasesTest extends TestCase
{
    private const METERS_PER_DEGREE = 6_371_000 * M_PI / 180; // ≈ 111 194.93 m

    private GeofenceService $geo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->geo = new GeofenceService();
    }

    #[Test]
    public function one_degree_of_longitude_on_the_equator(): void
    {
        $this->assertEqualsWithDelta(self::METERS_PER_DEGREE, $this->geo->calculateDistance(0.0, 0.0, 0.0, 1.0), 0.001);
        // Un grado de latitud mide lo mismo en cualquier meridiano
        $this->assertEqualsWithDelta(self::METERS_PER_DEGREE, $this->geo->calculateDistance(0.0, 0.0, 1.0, 0.0), 0.001);
    }

    #[Test]
    public function short_distance_across_the_antimeridian_is_not_the_long_way_around(): void
    {
        // 179.9995° E ↔ 179.9995° W: 0.001° de diferencia real (≈ 111 m), no 359.999°
        $d = $this->geo->calculateDistance(0.0, 179.9995, 0.0, -179.9995);
        $this->assertEqualsWithDelta(0.001 * self::METERS_PER_DEGREE, $d, 0.01);

        // Simétrico y también fuera del ecuador (Fiyi, -17°): se acorta con cos(lat)
        $fiji = $this->geo->calculateDistance(-17.0, -179.9995, -17.0, 179.9995);
        $this->assertEqualsWithDelta(0.001 * self::METERS_PER_DEGREE * cos(deg2rad(17.0)), $fiji, 0.01);
        $this->assertEqualsWithDelta($fiji, $this->geo->calculateDistance(-17.0, 179.9995, -17.0, -179.9995), 1e-9);
    }

    #[Test]
    public function it_resolves_a_single_meter(): void
    {
        $oneMeterInDegrees = 1 / self::METERS_PER_DEGREE;

        // 1 m al norte de la sede de Lima
        $north = $this->geo->calculateDistance(-12.09418, -77.03560, -12.09418 + $oneMeterInDegrees, -77.03560);
        $this->assertEqualsWithDelta(1.0, $north, 0.001);

        // 1 m al este en el ecuador
        $east = $this->geo->calculateDistance(0.0, 0.0, 0.0, $oneMeterInDegrees);
        $this->assertEqualsWithDelta(1.0, $east, 0.001);

        // 0.5 m: se distingue de 1 m (las geocercas redondean al metro)
        $half = $this->geo->calculateDistance(0.0, 0.0, 0.0, $oneMeterInDegrees / 2);
        $this->assertEqualsWithDelta(0.5, $half, 0.001);
    }

    #[Test]
    public function the_poles_and_antipodes_do_not_produce_nan(): void
    {
        $this->assertEqualsWithDelta(M_PI * 6_371_000, $this->geo->calculateDistance(0.0, 0.0, 0.0, 180.0), 0.01);
        $this->assertEqualsWithDelta(M_PI * 6_371_000, $this->geo->calculateDistance(90.0, 0.0, -90.0, 0.0), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->geo->calculateDistance(90.0, 10.0, 90.0, -170.0), 0.001);
    }
}
