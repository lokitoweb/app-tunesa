<?php

namespace App\Modules\Core\Notifications;

use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Services\CredentialProvisioner;
use App\Modules\Core\Support\TenantMailer;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Contraseña temporal pedida desde "¿Olvidaste tu contraseña?" (PasswordRecovery).
 * Solo por correo y en el momento: nunca va a la bandeja de la app ni a la base
 * de datos (la contraseña no debe quedar guardada en ningún lado).
 */
class TemporaryPasswordMail extends Notification
{
    public function __construct(private readonly string $password) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $company = rtrim(TenantSetting::forTenant($notifiable->tenant ?? null)->tradeName(), '.');

        return TenantMailer::apply((new MailMessage)
            ->subject("Tu contraseña temporal · {$company}")
            ->greeting("Hola, {$notifiable->first_name}")
            ->line('Pediste recuperar el acceso a tu cuenta. Esta es tu contraseña temporal:')
            ->line("**{$this->password}**")
            ->line('Ingresa con tu correo y esta contraseña (en la app o en el panel). Al entrar te pediremos crear una nueva, solo tuya.')
            ->line('Vence en ' . CredentialProvisioner::TEMP_TTL_HOURS . ' horas. Tu contraseña anterior ya no funciona y se cerraron tus sesiones abiertas.')
            ->line('Si no lo pediste tú, avisa de inmediato a Recursos Humanos.'), $notifiable);
    }
}
