<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envía un evento firmado a una integración de la empresa ("Marca y app" →
 * Integraciones): el webhook (webhook_url) o el endpoint de notificaciones
 * (notification_endpoint).
 *
 *   POST <url>
 *   Content-Type: application/json
 *   X-Tunesa-Event: attendance.clock_in
 *   X-Tunesa-Signature: sha256=<hex HMAC-SHA256 del cuerpo con webhook_secret>
 *
 * La URL y el secreto se leen al ejecutar el job (no viajan en la cola), así
 * que un secreto rotado o una URL borrada se respetan. 3 intentos con espera
 * creciente; el fallo final solo se registra en el log.
 */
class SendTenantWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const TARGET_WEBHOOK      = 'webhook_url';
    public const TARGET_NOTIFICATION = 'notification_endpoint';

    public int $tries = 3;

    public int $timeout = 15;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $target,
        public readonly string $event,
        public readonly string $body,
    ) {}

    /** Segundos de espera entre intentos */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(): void
    {
        $tenant = Tenant::find($this->tenantId);
        $row    = $tenant ? TenantSetting::rowFor($tenant) : null;
        $url    = $row?->{$this->target};

        if (blank($url)) {
            return; // Se quitó la integración después de encolar
        }

        $headers = ['X-Tunesa-Event' => $this->event];
        if (filled($row->webhook_secret)) {
            $headers['X-Tunesa-Signature'] = 'sha256=' . self::sign($this->body, $row->webhook_secret);
        }

        Http::timeout(5)
            ->connectTimeout(5)
            ->withHeaders($headers)
            ->withBody($this->body, 'application/json')
            ->post($url)
            ->throw();
    }

    public static function sign(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('No se pudo entregar un evento a la integración de la empresa', [
            'tenant_id' => $this->tenantId,
            'target'    => $this->target,
            'event'     => $this->event,
            'error'     => $e?->getMessage(),
        ]);
    }
}
