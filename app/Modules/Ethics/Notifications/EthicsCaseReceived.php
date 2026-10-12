<?php

namespace App\Modules\Ethics\Notifications;

use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Ethics\Models\EthicsCase;

/** A quien investiga: llegó una denuncia nueva (solo el código y la categoría) */
class EthicsCaseReceived extends AppNotification
{
    public function __construct(public readonly EthicsCase $case) {}

    public function type(): string
    {
        return 'ethics.case_received';
    }

    public function title(): string
    {
        return 'Nueva denuncia en el canal ético';
    }

    public function body(): string
    {
        return "Denuncia {$this->case->public_code}: {$this->case->categoryLabel()}. Revísala en el panel.";
    }

    public function payload(): array
    {
        return ['case_id' => $this->case->id, 'screen' => 'ethics_review'];
    }
}
