<?php

namespace App\Modules\Finance\Notifications;

use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\TenantMailer;
use App\Modules\Finance\Models\Requisition;
use Illuminate\Notifications\Messages\MailMessage;

/** A quien solicitó: su requerimiento fue ACEPTADO o RECHAZADO (correo por el SMTP de la empresa) */
class RequisitionReviewed extends AppNotification
{
    public function __construct(public readonly Requisition $requisition) {}

    protected function channels(object $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    public function type(): string
    {
        return 'requisition.' . $this->requisition->stage; // requisition.approved | requisition.rejected
    }

    public function title(): string
    {
        return $this->requisition->stage === 'approved' ? 'Requerimiento aceptado' : 'Requerimiento rechazado';
    }

    public function body(): string
    {
        $body = "{$this->requisition->code()}: {$this->requisition->detail}";

        return $this->requisition->stage === 'rejected' && $this->requisition->notes ? "{$body}. Motivo: {$this->requisition->notes}" : $body;
    }

    public function payload(): array
    {
        return ['requisition_id' => $this->requisition->id, 'screen' => 'requisitions'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return TenantMailer::apply((new MailMessage)
            ->subject("{$this->title()} · {$this->requisition->code()}")
            ->greeting("Hola, {$notifiable->first_name}")
            ->line($this->body())
            ->action('Ver requerimiento', route('requisitions.show', $this->requisition->id)), $notifiable);
    }
}
