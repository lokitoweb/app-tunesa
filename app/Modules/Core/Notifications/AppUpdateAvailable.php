<?php

namespace App\Modules\Core\Notifications;

/**
 * Al colaborador: se publicó una versión nueva de la app.
 * Solo push (no queda en la bandeja): la app ya muestra su propio aviso de
 * actualización al abrirse. Lo envía NotifyAppUpdate.
 */
class AppUpdateAvailable extends AppNotification
{
    public function __construct(public readonly string $version, public readonly bool $required = false) {}

    protected function channels(object $notifiable): array
    {
        return [];
    }

    public function type(): string
    {
        return 'app.update_available';
    }

    public function title(): string
    {
        return $this->required ? 'Actualiza la app' : 'Nueva versión de la app';
    }

    public function body(): string
    {
        return $this->required
            ? "Instala la versión {$this->version} para seguir marcando tu asistencia."
            : "Ya está disponible la versión {$this->version}. Actualízala para tener las últimas mejoras.";
    }

    public function payload(): array
    {
        return ['version' => $this->version, 'required' => $this->required, 'screen' => 'app_update'];
    }
}
