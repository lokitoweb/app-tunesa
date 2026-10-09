<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Base de las notificaciones de TuneSA (canal database).
 *
 * Lo guardado en notifications.data es siempre:
 *   { type, title, body, data: { ids y pistas de navegación } }
 * y es lo que devuelve GET /api/notifications y pinta la campana del panel.
 */
abstract class AppNotification extends Notification
{
    use Queueable;

    /** Identificador estable para la app (p. ej. "leave_request.reviewed") */
    abstract public function type(): string;

    abstract public function title(): string;

    abstract public function body(): string;

    /** Ids y pistas de navegación (screen, ids) */
    abstract public function payload(): array;

    public function via(object $notifiable): array
    {
        return ['database'];
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

    public function databaseType(object $notifiable): string
    {
        return $this->type();
    }
}
