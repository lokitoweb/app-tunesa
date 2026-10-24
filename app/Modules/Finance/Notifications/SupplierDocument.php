<?php

namespace App\Modules\Finance\Notifications;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Support\TenantMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Correo al proveedor con un documento adjunto (OC/OS emitida, comprobante de pago), por el
 * SMTP de la empresa que emite. Uno solo para todos los envíos al proveedor.
 *
 * @param  array<int, string>  $lines
 */
class SupplierDocument extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $subject,
        public readonly array $lines,
        public readonly ?string $attachment = null,
        public readonly ?string $attachmentName = null,
        public readonly string $greeting = 'Estimado proveedor:',
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->greeting($this->greeting);
        foreach ($this->lines as $line) {
            $mail->line($line);
        }
        if ($this->attachment !== null) {
            $mail->attachData($this->attachment, $this->attachmentName ?? 'documento.pdf', ['mime' => (new \finfo(FILEINFO_MIME_TYPE))->buffer($this->attachment) ?: 'application/pdf']);
        }

        return TenantMailer::apply($mail->salutation($this->tenant->company_name), $notifiable, $this->tenant);
    }
}
