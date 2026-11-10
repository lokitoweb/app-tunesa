<?php

namespace App\Modules\Core\Notifications;

use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Support\TenantMailer;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Enlace de un solo uso pedido desde "¿Olvidaste tu contraseña?" (PasswordRecovery). Solo por correo:
 * nunca va a la bandeja de la app ni a la base de datos (el enlace es la llave de la cuenta).
 */
class PasswordResetLinkMail extends Notification
{
    public function __construct(private readonly string $url, private readonly int $minutes) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $company = rtrim(TenantSetting::forTenant($notifiable->tenant ?? null)->tradeName(), '.');

        return TenantMailer::apply((new MailMessage)
            ->subject("Crea tu nueva contraseña · {$company}")
            ->greeting("Hola, {$notifiable->first_name}")
            ->line('Pediste recuperar el acceso a tu cuenta. Usa este botón para crear una nueva contraseña:')
            ->action('Crear nueva contraseña', $this->url)
            ->line("El enlace sirve una sola vez y vence en {$this->minutes} minutos. Tu contraseña actual sigue funcionando hasta que la cambies.")
            ->line('Si no lo pediste tú, ignora este correo: nadie puede entrar a tu cuenta sin este enlace. Si se repite, avisa a Recursos Humanos.'), $notifiable);
    }
}
