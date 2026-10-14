<?php

namespace App\Modules\Birthdays\Notifications;

use App\Modules\Core\Notifications\AppNotification;

/** A quien cumple años: saludo de la empresa */
class BirthdayGreeting extends AppNotification
{
    public function __construct(public readonly string $firstName, public readonly string $company) {}

    public function type(): string
    {
        return 'birthday.greeting';
    }

    public function title(): string
    {
        return "🎉 ¡Feliz cumpleaños, {$this->firstName}!";
    }

    public function body(): string
    {
        return "Todo el equipo de {$this->company} te desea un día increíble. ¡Que lo disfrutes!";
    }

    public function payload(): array
    {
        return ['screen' => 'birthdays'];
    }
}
