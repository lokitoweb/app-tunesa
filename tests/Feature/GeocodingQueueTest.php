<?php

namespace Tests\Feature;

use App\Modules\Attendance\Jobs\ReverseGeocodePunch;
use Tests\TestCase;

/**
 * Auditoría QA Ronda 2 · estrés: la geocodificación de marcaciones va en su propia cola de baja
 * prioridad (por defecto "geocoding") para que una hora pico no retrase los avisos push. El
 * scheduler la procesa con o sin worker permanente (routes/console.php). GEOCODING_QUEUE vacío:
 * la cola de siempre.
 */
class GeocodingQueueTest extends TestCase
{
    public function test_geocoding_has_its_own_queue_by_default(): void
    {
        $this->assertSame('geocoding', config('services.nominatim.queue'), 'sin GEOCODING_QUEUE en el .env');
        $this->assertSame('geocoding', (new ReverseGeocodePunch(1))->queue);

        // GEOCODING_QUEUE= (vacío) vuelve a la cola por defecto
        config(['services.nominatim.queue' => null]);
        $this->assertNull((new ReverseGeocodePunch(1))->queue);
    }
}
