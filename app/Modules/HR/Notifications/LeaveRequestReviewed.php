<?php

namespace App\Modules\HR\Notifications;

use App\Modules\Core\Notifications\AppNotification;
use App\Modules\HR\Models\LeaveRequest;
use Illuminate\Notifications\Messages\MailMessage;

/** Al colaborador: su solicitud de vacaciones / permiso / licencia fue aprobada o rechazada */
class LeaveRequestReviewed extends AppNotification
{
    public function __construct(public readonly LeaveRequest $leave) {}

    protected function channels(object $notifiable): array
    {
        return config('notifications.by_mail') && $notifiable->email
            ? ['database', 'mail']
            : ['database'];
    }

    public function type(): string
    {
        return 'leave_request.' . $this->leave->status; // leave_request.approved | leave_request.rejected
    }

    public function title(): string
    {
        return $this->approved() ? 'Solicitud aprobada' : 'Solicitud rechazada';
    }

    public function body(): string
    {
        $body = sprintf(
            '%s del %s al %s: %s.',
            $this->leave->type_label,
            $this->leave->start_date?->format('d/m/Y'),
            $this->leave->end_date?->format('d/m/Y'),
            $this->approved() ? 'aprobada' : 'rechazada'
        );

        return $this->leave->review_notes ? "{$body} Motivo: {$this->leave->review_notes}" : $body;
    }

    public function payload(): array
    {
        return [
            'leave_request_id' => $this->leave->id,
            'status'           => $this->leave->status,
            'screen'           => 'leave_requests',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return \App\Modules\Core\Support\TenantMailer::apply((new MailMessage)
            ->subject($this->title())
            ->greeting("Hola, {$notifiable->first_name}")
            ->line($this->body())
            ->line('Puedes ver el detalle en la app.'), $notifiable);
    }

    private function approved(): bool
    {
        return $this->leave->status === 'approved';
    }
}
