<?php

namespace App\Modules\HR\Notifications;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Support\TenantMailer;
use App\Modules\HR\Services\ExpiryAlerts;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Correo diario con lo que vence (por el SMTP de la empresa) */
class ExpiryDigest extends Notification
{
    use Queueable;

    public function __construct(public readonly Tenant $tenant, public readonly Collection $items, public readonly bool $test = false) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(($this->test ? 'Prueba de alertas' : 'Vencimientos') . " · {$this->tenant->company_name}")
            ->greeting('Hola:')
            ->line($this->test
                ? 'Este es un correo de prueba: las alertas de vencimientos llegan a esta dirección.'
                : 'Esto vence en los próximos días o ya venció:');

        foreach ($this->items as $i) {
            $mail->line("• {$i['kind_label']}: **{$i['title']}**" . ($i['who'] ? " — {$i['who']}" : '')
                . ' · ' . ExpiryAlerts::when($i['days_left']) . ' (' . Carbon::parse($i['date'])->format('d/m/Y') . ')');
        }
        if ($this->items->isEmpty()) {
            $mail->line('No hay vencimientos en los próximos 30 días.');
        }

        return TenantMailer::apply($mail->action('Ver vencimientos', url('/expirations'))->salutation($this->tenant->company_name), $notifiable, $this->tenant);
    }
}
