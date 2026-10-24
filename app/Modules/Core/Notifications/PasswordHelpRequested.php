<?php

namespace App\Modules\Core\Notifications;

use App\Modules\Core\Models\User;

/** A RRHH (o al administrador): un colaborador olvidó su clave y pide que restablezcan su acceso. */
class PasswordHelpRequested extends AppNotification
{
    public function __construct(public readonly User $employee) {}

    public function type(): string
    {
        return 'auth.password_help';
    }

    public function title(): string
    {
        return 'Olvidó su contraseña';
    }

    public function body(): string
    {
        return "{$this->employee->full_name} olvidó su contraseña de la app. Restablece su acceso desde Colaboradores "
            . 'y entrégale la contraseña temporal en persona o por un canal seguro.';
    }

    public function payload(): array
    {
        return ['user_id' => $this->employee->id];
    }
}
