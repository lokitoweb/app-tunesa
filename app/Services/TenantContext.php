<?php

namespace App\Services;

use App\Models\Tenant;
use RuntimeException;

/**
 * TenantContext (Singleton)
 *
 * Almacena el tenant activo durante el ciclo de vida de una request HTTP.
 * Es el punto central de verdad sobre qué empresa está operando.
 *
 * Uso:
 *   // Obtener el tenant actual (lanza excepción si no está seteado)
 *   $tenant = app(TenantContext::class)->get();
 *
 *   // O via helper global definido en AppServiceProvider:
 *   $tenant = current_tenant();
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    /**
     * Registra el tenant activo para la request actual.
     */
    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    /**
     * Retorna el tenant activo.
     * @throws RuntimeException si no hay tenant resuelto (uso fuera del middleware).
     */
    public function get(): Tenant
    {
        if (is_null($this->tenant)) {
            throw new RuntimeException(
                'No hay un Tenant resuelto. ' .
                'Asegúrate de que la ruta esté protegida por el middleware [tenant].'
            );
        }

        return $this->tenant;
    }

    /**
     * Retorna el tenant o null si no está seteado.
     * Útil en contextos donde el tenant puede ser opcional (ej: super_admin).
     */
    public function getOrNull(): ?Tenant
    {
        return $this->tenant;
    }

    /**
     * Retorna el ID del tenant activo.
     */
    public function id(): int
    {
        return $this->get()->id;
    }

    /**
     * Verifica si hay un tenant resuelto.
     */
    public function resolved(): bool
    {
        return ! is_null($this->tenant);
    }

    /**
     * Limpia el tenant (útil en tests).
     */
    public function clear(): void
    {
        $this->tenant = null;
    }
}
