<?php

namespace App\Modules\Integrations\Jobs;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Integrations\Models\WebhookDelivery;
use App\Modules\Integrations\Services\WebhookSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Envía un evento firmado a una integración de la empresa ("Marca y app" →
 * Integraciones): el webhook (webhook_url) o el endpoint de notificaciones
 * (notification_endpoint). Ver WebhookSender para el formato y la firma.
 *
 * La URL y el secreto se leen al ejecutar el job (no viajan en la cola), así
 * que un secreto rotado o una URL borrada se respetan.
 *
 * Trazabilidad: cada intento actualiza la misma fila de `webhook_deliveries`
 * (identificada por $deliveryUuid): intento, código HTTP, tiempo, respuesta y,
 * si fallará de nuevo, `next_retry_at`. 5 intentos con espera exponencial
 * (10 s, 1 min, 5 min, 15 min); el fallo final queda como `failed` y en el log.
 */
class SendTenantWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const TARGET_WEBHOOK      = 'webhook_url';
    public const TARGET_NOTIFICATION = 'notification_endpoint';

    /** Esperas entre intentos, en segundos (una menos que $tries) */
    public const BACKOFF = [10, 60, 300, 900];

    public int $tries = 5;

    public int $timeout = 30;

    public string $deliveryUuid;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $target,
        public readonly string $event,
        public readonly string $body,
        ?string $deliveryUuid = null,
    ) {
        $this->deliveryUuid = $deliveryUuid ?? (string) Str::uuid();
    }

    /** Segundos de espera entre intentos */
    public function backoff(): array
    {
        return self::BACKOFF;
    }

    public function handle(WebhookSender $sender): void
    {
        $tenant = Tenant::find($this->tenantId);
        $row    = $tenant ? TenantSetting::rowFor($tenant) : null;
        $url    = $row?->{$this->target};

        if (blank($url)) {
            return; // Se quitó la integración después de encolar
        }

        $delivery = $sender->open($this->tenantId, $this->target, $this->event, $url, $this->body, $this->deliveryUuid);
        $attempt  = $this->attempts();

        $result = $sender->send($url, $this->event, $this->body, $row->webhook_secret, $delivery, $attempt, $this->retryIn($attempt));

        if (! $result['ok']) {
            // La cola lo reintenta según backoff(); con la cola "sync" lo captura IntegrationDispatcher
            throw new RuntimeException("Integración {$this->target} ({$this->event}): {$result['error']}");
        }
    }

    /** Segundos hasta el siguiente intento, o null si este es el último */
    public function retryIn(int $attempt): ?int
    {
        // Con la cola "sync" (o el job ejecutado a mano) no hay reintentos
        $queued = $this->job !== null && $this->job->getConnectionName() !== 'sync';

        if (! $queued || $attempt >= $this->tries) {
            return null;
        }

        return self::BACKOFF[$attempt - 1] ?? self::BACKOFF[count(self::BACKOFF) - 1];
    }

    /** Compatibilidad: firma HMAC del cuerpo (ver WebhookSender::sign) */
    public static function sign(string $body, string $secret): string
    {
        return WebhookSender::sign($body, $secret);
    }

    public function failed(?Throwable $e): void
    {
        try {
            app(WebhookSender::class)->fail(
                WebhookDelivery::firstWhere('uuid', $this->deliveryUuid),
                Str::limit($e?->getMessage() ?? 'Falló el envío.', 500),
            );
        } catch (Throwable) {
            // Solo trazabilidad
        }

        Log::warning('No se pudo entregar un evento a la integración de la empresa', [
            'tenant_id' => $this->tenantId,
            'target'    => $this->target,
            'event'     => $this->event,
            'delivery'  => $this->deliveryUuid,
            'error'     => $e?->getMessage(),
        ]);
    }
}
