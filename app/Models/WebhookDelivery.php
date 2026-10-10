<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un envío a una integración de la empresa (webhook o endpoint de
 * notificaciones) con el resultado de su último intento: código HTTP, tiempo,
 * respuesta o error, y cuándo se reintenta.
 *
 * No usa BelongsToTenant: tenant_id NULL son las pruebas de la vista global
 * (plataforma). Las consultas filtran tenant_id de forma explícita (ofTenant).
 */
class WebhookDelivery extends Model
{
    use Prunable;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';

    /** Días que se conservan (`php artisan model:prune`, programado a diario) */
    public const RETENTION_DAYS = 30;

    protected $fillable = [
        'uuid', 'tenant_id', 'target', 'event', 'url', 'request_body', 'signature', 'attempt', 'status',
        'http_status', 'response_ms', 'response_body', 'error', 'next_retry_at',
    ];

    protected $casts = [
        'attempt'       => 'integer',
        'http_status'   => 'integer',
        'response_ms'   => 'integer',
        'next_retry_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeOfTenant(Builder $query, ?int $tenantId): Builder
    {
        return $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', $tenantId);
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    public function host(): string
    {
        return parse_url($this->url, PHP_URL_HOST) ?: $this->url;
    }
}
