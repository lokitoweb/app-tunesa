<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Core\Casts\UtcDateTime;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Concerns\BelongsToBranchScope;
use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Intento de marcación rechazado (ver migración create_attendance_punch_attempts_table).
 * Solo se inserta: es evidencia y no se edita.
 */
class AttendancePunchAttempt extends Model
{
    use BelongsToTenant, BelongsToBranchScope;

    public const BRANCH_SCOPE = ['branch_id', 'user_id'];

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'user_id', 'branch_id', 'type', 'error_code', 'message',
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
