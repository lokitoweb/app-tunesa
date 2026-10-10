<?php

namespace App\Modules\Integrations\Jobs;

use App\Modules\Core\Models\Scopes\TenantScope;
use App\Modules\Core\Models\User;
use App\Modules\Integrations\Services\Push\FcmSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Envía un push FCM a un usuario (lo encola FcmChannel).
 *
 * Reintenta errores transitorios (red, 429, 5xx); un rechazo definitivo
 * (token inválido, mensaje mal formado) se registra y no se reintenta.
 */
class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly int $userId,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
    ) {}

    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(FcmSender $sender): void
    {
        $user = User::withoutGlobalScope(TenantScope::class)->find($this->userId);
        if (! $user || blank($user->fcm_token)) {
            return;
        }

        try {
            $result = $sender->send($user, $this->title, $this->body, $this->data);
        } catch (\Throwable $e) {
            Log::warning('Push FCM no enviado: ' . $e->getMessage(), ['user_id' => $this->userId]);

            return;
        }

        if ($result->ok) {
            return;
        }

        // Con la cola "sync" no hay reintentos: lanzar solo tumbaría la acción que originó el aviso
        $retryable = $this->job && ! $this->job instanceof \Illuminate\Queue\Jobs\SyncJob;

        if ($result->transient && $retryable && $this->attempts() < $this->tries) {
            throw new RuntimeException("Push FCM falló ({$result->status}): {$result->error}");
        }

        Log::warning('Push FCM no enviado: ' . $result->error, [
            'user_id' => $this->userId,
            'status'  => $result->status,
            'code'    => $result->code,
        ]);
    }
}
