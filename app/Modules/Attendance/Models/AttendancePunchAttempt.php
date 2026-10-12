<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Core\Casts\UtcDateTime;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Concerns\BelongsToBranchScope;
use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Intento de marcación rechazado (ver migración create_attendance_punch_attempts_table).
 * Solo se inserta: es evidencia y no se edita. Se borra a los RETENTION_MONTHS meses.
 */
class AttendancePunchAttempt extends Model
{
    use BelongsToTenant, BelongsToBranchScope, Prunable;

    public const BRANCH_SCOPE = ['branch_id', 'user_id'];

    public const UPDATED_AT = null;

    /** Origen: el backend rechazó la marcación o la app la bloqueó antes de enviarla */
    public const SOURCE_SERVER = 'server';
    public const SOURCE_APP    = 'app';

    /** Bloqueos que la app reporta (POST /api/attendance/attempts) */
    public const APP_CODES = ['MOCK_LOCATION_DETECTED', 'GPS_ACCURACY_TOO_LOW', 'GEOFENCE_VIOLATION'];

    /** Meses que se guardan los intentos (model:prune) */
    public const RETENTION_MONTHS = 12;

    protected $fillable = [
        'tenant_id', 'user_id', 'branch_id', 'type', 'error_code', 'source', 'message',
        'latitude', 'longitude', 'accuracy_meters', 'distance_meters', 'allowed_radius',
        'mock_location_detected', 'device_flags', 'offline', 'offline_recorded_at', 'device_time',
        'client_uuid', 'device_id', 'device_os', 'app_version', 'ip',
    ];

    protected $casts = [
        'latitude'               => 'float',
        'longitude'              => 'float',
        'accuracy_meters'        => 'float',
        'distance_meters'        => 'integer',
        'allowed_radius'         => 'integer',
        'mock_location_detected' => 'boolean',
        'device_flags'           => 'array',
        'offline'                => 'boolean',
        'offline_recorded_at'    => UtcDateTime::class,
        'device_time'            => UtcDateTime::class,
        'created_at'             => 'datetime',
    ];

    /** Intentos de más de RETENTION_MONTHS meses (model:prune, de madrugada) */
    public function prunable(): Builder
    {
        return static::withoutGlobalScopes()->where('created_at', '<', now()->subMonths(self::RETENTION_MONTHS));
    }

    public function fromApp(): bool
    {
        return $this->source === self::SOURCE_APP;
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function mapUrl(): ?string
    {
        return $this->latitude !== null && $this->longitude !== null
            ? "https://www.google.com/maps?q={$this->latitude},{$this->longitude}"
            : null;
    }
}
