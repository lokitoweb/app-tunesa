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

use App\Modules\Core\Services\TenantContext;

if (! function_exists('current_tenant')) {
    /**
     * Retorna el tenant activo en la request actual.
     */
    function current_tenant(): \App\Modules\Core\Models\Tenant
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

if (! function_exists('current_tenant_id_or_null')) {
    /**
     * ID del tenant activo o null (Super Admin en la vista global del panel).
     * Úsalo con el scope forTenant(): X::forTenant(current_tenant_id_or_null()).
     */
    function current_tenant_id_or_null(): ?int
    {
        $ctx = app(TenantContext::class);
        return $ctx->resolved() ? $ctx->id() : null;
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
    function attendance_photo_url(\App\Modules\Attendance\Models\Attendance $attendance, string $moment = 'in'): ?string
    {
        return app(\App\Modules\Attendance\Services\AttendanceEvidence::class)->photoUrl($attendance, $moment);
    }
}

if (! function_exists('attendance_photo_thumb_url')) {
    /**
     * Miniatura de 160 px (firmada) de la selfie de entrada ('in') o salida ('out'), para listados.
     */
    function attendance_photo_thumb_url(\App\Modules\Attendance\Models\Attendance $attendance, string $moment = 'in'): ?string
    {
        return app(\App\Modules\Attendance\Services\AttendanceEvidence::class)->thumbUrl($attendance, $moment);
    }
}

// =============================================================================
// Presentación de datos (App\Modules\Core\Support\Labels): nada de claves ni enums crudos en la UI
// =============================================================================

if (! function_exists('label')) {
    /**
     * Etiqueta en español de un valor de enum: label('leave_status', 'approved') → "Aprobada".
     * Conjuntos en App\Modules\Core\Support\Labels::ENUMS (attendance_status, leave_status, leave_type,
     * document_status, document_type, payslip_status, user_role, user_status, tenant_status,
     * tenant_plan, shift_type, branch_status, notification_type, holiday_type, uniform_item...).
     */
    function label(string $set, mixed $value): string
    {
        return \App\Modules\Core\Support\Labels::enum($set, $value);
    }
}

if (! function_exists('field_label')) {
    /** Nombre en español de una columna: field_label('default_grace_minutes') → "Tolerancia por defecto (min)" */
    function field_label(string $field): string
    {
        return \App\Modules\Core\Support\Labels::field($field);
    }
}

if (! function_exists('field_value')) {
    /**
     * Valor formateado según la columna (Sí/No, unidades, fechas en la zona de la empresa,
     * muestra de color, secretos enmascarados). Devuelve HtmlString solo para colores.
     */
    function field_value(string $field, mixed $value, bool $inherits = false): string|\Illuminate\Support\HtmlString
    {
        return \App\Modules\Core\Support\Labels::value($field, $value, null, $inherits);
    }
}

if (! function_exists('masked')) {
    /** Secreto para mostrar: "sk_live_abcdef1234" → "••••••••1234" */
    function masked(?string $secret): ?string
    {
        return \App\Modules\Core\Support\SensitiveFields::maskString($secret);
    }
}
