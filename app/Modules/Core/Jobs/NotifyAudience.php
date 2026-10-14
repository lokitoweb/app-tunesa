<?php

namespace App\Modules\Core\Jobs;

use App\Modules\Core\Models\Scopes\TenantScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Avisa (bandeja + push) al público de un contenido con HasAudience, por
 * lotes y en la cola: publicar un beneficio para miles de personas no
 * bloquea el panel.
 *
 *   NotifyAudience::dispatch(Benefit::class, $benefit->id, BenefitPublished::class);
 *
 * La notificación se arma con el contenido recién leído: si lo despublicaron
 * antes de que corra el job, no se avisa.
 */
class NotifyAudience implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $modelClass
     * @param class-string<\App\Modules\Core\Notifications\AppNotification> $notificationClass
     */
    public function __construct(
        public readonly string $modelClass,
        public readonly int $modelId,
        public readonly string $notificationClass,
    ) {}

    public function handle(): void
    {
        $subject = $this->modelClass::withoutGlobalScope(TenantScope::class)->find($this->modelId);
        if (! $subject || ($subject->status ?? 'published') !== 'published') {
            return;
        }

        $notification = new ($this->notificationClass)($subject);
        $sent = 0;

        $subject->audienceUsers()->select('users.*')->chunkById(300, function ($users) use ($notification, &$sent) {
            Notification::send($users, $notification);
            $sent += $users->count();
        }, 'users.id', 'id');

        Log::info('notify_audience', ['subject' => class_basename($this->modelClass), 'id' => $this->modelId, 'recipients' => $sent]);
    }
}
