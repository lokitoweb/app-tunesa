<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Suspensión del servicio en TODA la instalación (p. ej. el cliente que compró el sistema no pagó): nadie entra
 * a la web ni a la app y se ve «Servicio suspendido». Usa el modo mantenimiento nativo de Laravel (corta web y
 * API antes que nada, y el programador de tareas y las colas no corren) más una marca propia que distingue la
 * suspensión de las actualizaciones: scripts/deploy.sh no reactiva una plataforma suspendida.
 * Solo el Super Admin entra, con su enlace de acceso (cookie de Laravel), para reactivarla.
 */
class ServiceSuspension
{
    public const DEFAULT_MESSAGE = 'El servicio está suspendido. Comunícate con el proveedor del sistema para reactivarlo.';

    public function __construct(private readonly AuditLogger $audit) {}

    public static function markerPath(): string
    {
        return storage_path('framework/service-suspended');
    }

    /** Suspendida de verdad: marca propia + modo mantenimiento (un «php artisan up» manual la deja inactiva) */
    public static function active(): bool
    {
        return File::exists(self::markerPath()) && app()->maintenanceMode()->active();
    }

    public static function message(): string
    {
        $data = File::exists(self::markerPath()) ? json_decode((string) File::get(self::markerPath()), true) : null;

        return trim((string) ($data['message'] ?? '')) ?: self::DEFAULT_MESSAGE;
    }

    /** Enlace con el que el Super Admin entra mientras está suspendida (null si no lo está) */
    public static function ownerLink(): ?string
    {
        $secret = self::active() ? (app()->maintenanceMode()->data()['secret'] ?? null) : null;

        return $secret ? url($secret) : null;
    }

    /** @return string enlace de acceso del Super Admin */
    public function suspend(User $by, ?string $message): string
    {
        $secret = Str::random(40);
        File::put(self::markerPath(), json_encode([
            'message' => trim((string) $message) ?: null, 'by' => $by->id, 'at' => now()->toIso8601String(),
        ]));
        Artisan::call('down', ['--secret' => $secret, '--retry' => 300]);
        $this->audit->log('platform.suspended', null, ['message' => self::message()]);

        return url($secret);
    }

    public function resume(User $by): void
    {
        Artisan::call('up');
        File::delete(self::markerPath());
        $this->audit->log('platform.resumed', null, ['by' => $by->full_name]);
    }
}
