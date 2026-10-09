<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Registra acciones sensibles en audit_logs (ver datos bancarios completos,
 * abrir documentos del legajo, exportar planilla, aprobar/rechazar...).
 */
class AuditLogger
{
    public function log(string $action, ?Model $subject = null, array $meta = []): void
    {
        $request = request();
        $context = app(TenantContext::class);

        AuditLog::create([
            'tenant_id'    => $context->resolved() ? $context->id() : ($subject?->getAttribute('tenant_id')),
            'user_id'      => $request?->user()?->id,
            'action'       => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id'   => $subject?->getKey(),
            'ip'           => $request?->ip(),
            'user_agent'   => substr((string) $request?->userAgent(), 0, 255) ?: null,
            'meta'         => $meta ?: null,
        ]);
    }
}
