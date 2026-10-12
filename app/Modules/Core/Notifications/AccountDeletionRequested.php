<?php

namespace App\Modules\Core\Notifications;

use App\Modules\Core\Models\AccountDeletionRequest;

/** A RRHH (o al administrador): un colaborador pidió eliminar su cuenta y sus datos. */
class AccountDeletionRequested extends AppNotification
{
    public function __construct(public readonly AccountDeletionRequest $request) {}

    public function type(): string
    {
        return 'account.deletion_requested';
    }

    public function title(): string
    {
        return 'Pedido de eliminación de cuenta';
    }

    public function body(): string
    {
        $name = $this->request->user?->full_name ?: $this->request->email;

        return "{$name} pidió eliminar su cuenta y sus datos personales. Atiéndelo en un máximo de 30 días; "
            . 'conserva solo lo que la ley laboral obliga a guardar (planillas, boletas y marcaciones).';
    }

    public function payload(): array
    {
        return array_filter([
            'request_id' => $this->request->id,
            'user_id'    => $this->request->user_id,
        ]);
    }
}
