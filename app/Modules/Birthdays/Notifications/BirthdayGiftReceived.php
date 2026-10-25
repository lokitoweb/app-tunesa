<?php

namespace App\Modules\Birthdays\Notifications;

use App\Modules\Birthdays\Services\BirthdayGiftService;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AppNotification;

/** Al cumpleañero: un compañero le envió un regalo animado */
class BirthdayGiftReceived extends AppNotification
{
    public function __construct(public readonly User $sender, public readonly string $giftType) {}

    public function type(): string
    {
        return 'birthday.gift';
    }

    public function title(): string
    {
        $gift = BirthdayGiftService::CATALOG[$this->giftType] ?? null;

        return ($gift['emoji'] ?? '🎁') . " {$this->sender->first_name} te envió " . mb_strtolower($gift['label'] ?? 'un regalo');
    }

    public function body(): string
    {
        return 'Ábrelo hoy en la app: los regalos se pueden ver animados solo durante tu cumpleaños.';
    }

    public function payload(): array
    {
        return ['sender_id' => $this->sender->id, 'gift_type' => $this->giftType, 'screen' => 'birthdays'];
    }
}
