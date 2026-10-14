<?php

namespace App\Modules\Benefits\Notifications;

use App\Modules\Benefits\Models\Benefit;
use App\Modules\Core\Notifications\AppNotification;

/** Al personal del público: hay un beneficio nuevo */
class BenefitPublished extends AppNotification
{
    public function __construct(public readonly Benefit $benefit) {}

    public function type(): string
    {
        return 'benefit.published';
    }

    public function title(): string
    {
        return '🎁 Nuevo beneficio para ti';
    }

    public function body(): string
    {
        $highlight = $this->benefit->highlight ? "{$this->benefit->highlight}: " : '';
        $partner = $this->benefit->partner ? " con {$this->benefit->partner}" : '';

        return "{$highlight}{$this->benefit->title}{$partner}.";
    }

    public function payload(): array
    {
        return ['benefit_id' => $this->benefit->id, 'screen' => 'benefits'];
    }
}
