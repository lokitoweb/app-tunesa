<?php

/**
 * =============================================================================
 * app/helpers.php — Helpers globales multi-tenant
 *
 * Cargado por Composer ("autoload.files"). Deben vivir en el namespace global:
 * declararlos dentro de TenantServiceProvider los dejaba como
 * App\Providers\current_tenant() y no se encontraban desde los controladores.
 * =============================================================================
 */

use App\Services\TenantContext;

if (! function_exists('current_tenant')) {
    /**
     * Retorna el tenant activo en la request actual.
     */
    function current_tenant(): \App\Models\Tenant
    {
        return app(TenantContext::class)->get();
    }
}

if (! function_exists('current_tenant_id')) {
    /**
     * Retorna el ID del tenant activo.
     * Útil para queries: User::where('tenant_id', current_tenant_id())
     */
    function current_tenant_id(): int
    {
        return app(TenantContext::class)->id();
    }
}

if (! function_exists('tenant_timezone')) {
    /**
     * Retorna la zona horaria del tenant activo.
     * Útil para Carbon: now(tenant_timezone())
     */
    function tenant_timezone(): string
    {
        $ctx = app(TenantContext::class);
        return $ctx->resolved() ? $ctx->get()->timezone : config('app.timezone');
    }
}

if (! function_exists('attendance_photo_url')) {
    /**
     * URL temporal (firmada) de la selfie de entrada ('in') o salida ('out').
     */
    function attendance_photo_url(\App\Models\Attendance $attendance, string $moment = 'in'): ?string
    {
        return app(\App\Services\AttendanceEvidence::class)->photoUrl($attendance, $moment);
    }
}
