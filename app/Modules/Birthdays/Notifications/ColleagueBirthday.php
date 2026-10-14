<?php

namespace App\Modules\Birthdays\Notifications;

use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AppNotification;

/** A los compañeros: hoy cumple años alguien de su equipo */
class ColleagueBirthday extends AppNotification
{
    public function __construct(public readonly User $celebrant) {}

    public function type(): string
    {
        return 'birthday.colleague';
    }

    public function title(): string
    {
        return "🎂 Hoy es el cumpleaños de {$this->celebrant->full_name}";
    }

    public function body(): string
    {
        $position = $this->celebrant->position ? " ({$this->celebrant->position})" : '';

        return "Salúdalo{$position} y hazle el día especial.";
    }

    public function payload(): array
    {
        return ['user_id' => $this->celebrant->id, 'screen' => 'birthdays'];
    }
}
