<?php

namespace App\Modules\Integrations\Notifications\Channels;

use App\Modules\Integrations\Jobs\SendPushNotification;
use App\Modules\Core\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Canal de notificaciones "push" (FCM). AppNotification lo agrega en via()
 * cuando el destinatario puede recibir push (FcmSender::canSendTo).
 *
 * No llama a FCM en la petición: encola SendPushNotification (después del
 * commit) para que la acción que originó el aviso no espere a Google.
 */
class FcmChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || blank($notifiable->fcm_token) || ! method_exists($notification, 'toFcm')) {
            return;
        }

        try {
            $message = $notification->toFcm($notifiable);

            SendPushNotification::dispatch(
                $notifiable->id,
                (string) ($message['title'] ?? ''),
                (string) ($message['body'] ?? ''),
                (array) ($message['data'] ?? []),
            )->afterCommit();
        } catch (\Throwable $e) {
            Log::warning('No se pudo encolar el push: ' . $e->getMessage(), ['user_id' => $notifiable->id]);
        }
    }
}
