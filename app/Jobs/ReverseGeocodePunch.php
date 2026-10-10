<?php

namespace App\Jobs;

use App\Models\AttendancePunch;
use App\Models\Tenant;
use App\Services\Geocoding\GeocodingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Completa attendance_punches.address con la geocodificación inversa del
 * punto GPS (proveedor efectivo de la empresa). Se encola después del commit
 * de cada marcación. Es la única escritura permitida sobre la bitácora
 * (AttendancePunch::fillAddress).
 */
class ReverseGeocodePunch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $punchId) {}

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(GeocodingService $geocoding): void
    {
        $punch = AttendancePunch::withoutGlobalScopes()->find($this->punchId);
        if (! $punch || filled($punch->address)) {
            return;
        }

        try {
            $result = $geocoding->forTenant(Tenant::find($punch->tenant_id))->reverse($punch->latitude, $punch->longitude);
        } catch (\Throwable $e) {
            // En una cola real se reintenta; con "sync" solo queda en el log (nunca tumba la marcación)
            if ($this->job && ! $this->job instanceof SyncJob && $this->attempts() < $this->tries) {
                throw $e;
            }

            Log::warning('No se pudo obtener la dirección de la marcación: ' . $e->getMessage(), ['punch_id' => $this->punchId]);

            return;
        }

        if ($result['address']) {
            AttendancePunch::fillAddress($punch->id, $result['address']);
        }
    }
}
