<?php

namespace App\Modules\Core\Notifications;

use App\Modules\Integrations\Notifications\Channels\FcmChannel;
use App\Modules\Integrations\Services\Push\FcmSender;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Base de las notificaciones de TuneSA (canal database + push FCM).
 *
 * Lo guardado en notifications.data es siempre:
 *   { type, title, body, data: { ids y pistas de navegación } }
 * y es lo que devuelve GET /api/notifications y pinta la campana del panel.
 *
 * Push: si la empresa del destinatario tiene fcm_enabled (y la plataforma
 * cargó la cuenta de servicio de Firebase) y el usuario tiene fcm_token, el
 * mismo aviso sale también por FcmChannel. withoutPush() lo deja solo in-app
 * (p. ej. copias para el supervisor).
 */
abstract class AppNotification extends Notification
{
    use Queueable;

    protected bool $push = true;

    /** Identificador estable para la app (p. ej. "leave_request.reviewed") */
    abstract public function type(): string;

    abstract public function title(): string;

    abstract public function body(): string;

    /** Ids y pistas de navegación (screen, ids) */
    abstract public function payload(): array;

    /** Canales propios de la notificación (sin push); las subclases agregan mail, etc. */
    protected function channels(object $notifiable): array
    {
        return ['database'];
    }

    public function via(object $notifiable): array
    {
        $channels = $this->channels($notifiable);

        if ($this->push && app(FcmSender::class)->canSendTo($notifiable)) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    public function withoutPush(): static
    {
        $this->push = false;

        return $this;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'  => $this->type(),
            'title' => $this->title(),
            'body'  => $this->body(),
            'data'  => $this->payload(),
        ];
    }

    /** Mensaje push: título, cuerpo y data (FCM exige valores string; los convierte FcmSender) */
    public function toFcm(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'body'  => $this->body(),
            'data'  => ['type' => $this->type(), 'notification_id' => $this->id] + $this->payload(),
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->type();
    }
}
