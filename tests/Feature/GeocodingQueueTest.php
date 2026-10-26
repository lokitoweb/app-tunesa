<?php

namespace Tests\Feature;

use App\Modules\Attendance\Jobs\ReverseGeocodePunch;
use Tests\TestCase;

/**
 * Auditoría QA Ronda 2 · estrés: la geocodificación de marcaciones puede ir en su propia cola
 * (GEOCODING_QUEUE) para que una hora pico no retrase los avisos push. Sin configurar, la de siempre.
 */
class GeocodingQueueTest extends TestCase
{
    public function test_default_queue_unless_configured(): void
    {
        $this->assertNull((new ReverseGeocodePunch(1))->queue, 'Sin GEOCODING_QUEUE: cola por defecto, como antes');

        config(['services.nominatim.queue' => 'geocoding']);
        $this->assertSame('geocoding', (new ReverseGeocodePunch(1))->queue);
    }
}
