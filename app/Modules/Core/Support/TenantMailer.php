<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Remitente de los correos transaccionales: el SMTP de la empresa del destinatario
 * (o el de la plataforma), configurado en Integraciones por el SuperAdmin. Sin SMTP
 * propio se usa el mailer por defecto del .env. Un mensaje = un envío por un solo
 * mailer: no hay reenvíos por otro canal.
 */
final class TenantMailer
{
    /** Aplica mailer y remitente al correo de una notificación */
    public static function apply(MailMessage $message, object $notifiable): MailMessage
    {
        $tenant = $notifiable->tenant ?? null;
        $smtp = TenantSetting::forTenant($tenant instanceof Tenant ? $tenant : null)->smtp();

        if (! $smtp) {
            return $message;
        }

        $name = 'tenant_smtp_' . ($tenant?->id ?? 'platform');
        config(["mail.mailers.{$name}" => $smtp]);

        return $message->mailer($name)->from($smtp['from']['address'], $smtp['from']['name']);
    }
}
