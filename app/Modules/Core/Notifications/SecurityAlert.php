<?php

namespace App\Modules\Core\Notifications;

use Illuminate\Support\Carbon;

/**
 * A la persona: actividad sensible en su cuenta (no se puede apagar).
 *   new_device       → inicio de sesión desde un dispositivo nuevo
 *   password_changed → se cambió la contraseña
 */
class SecurityAlert extends AppNotification
{
    public function __construct(
        public readonly string $kind,
        public readonly ?string $device = null,
        public readonly ?string $at = null,
    ) {}

    protected function channels(object $notifiable): array
    {
        return config('notifications.by_mail') && ($notifiable->email ?? null) ? ['database', 'mail'] : ['database'];
    }

    public function type(): string
    {
        return 'security.' . $this->kind;
    }

    public function title(): string
    {
        return $this->kind === 'new_device' ? 'Nuevo inicio de sesión' : 'Cambiaste tu contraseña';
    }

    public function body(): string
    {
        $when = $this->at ? Carbon::parse($this->at)->format('d/m/Y h:i a') : null;

        return $this->kind === 'new_device'
            ? sprintf('Se inició sesión en %s%s. Si no fuiste tú, cambia tu contraseña y cierra esa sesión en Mi perfil › Mis dispositivos.',
                $this->device ?: 'un dispositivo nuevo', $when ? " el {$when}" : '')
            : 'Tu contraseña se cambió y se cerró la sesión en tus otros dispositivos. Si no fuiste tú, avisa a RRHH de inmediato.';
    }

    public function payload(): array
    {
        return array_filter(['device' => $this->device, 'screen' => 'devices']);
    }

    public function toMail(object $notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        return (new \Illuminate\Notifications\Messages\MailMessage)->subject($this->title())->line($this->body());
    }
}
