<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Casts\UtcDateTime;
use App\Modules\Core\Models\Concerns\BelongsToBranchScope;
use App\Modules\Core\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Bitácora inmutable de marcaciones (ver migración create_attendance_punches_table).
 *
 * Una fila por evento (clock_in, break_start, break_end, clock_out), escrita en
 * la misma transacción que el cambio en `attendances`. El modelo rechaza
 * cualquier update o delete; la única columna que se completa después es
 * `address` (geocodificación inversa), y solo con fillAddress(), que usa el
 * query builder sin pasar por los eventos del modelo. Un trigger de la base de
 * datos protege las demás columnas aunque alguien use el query builder.
 *
 * device_time, server_time y offline_recorded_at están en UTC (UtcDateTime).
 */
class AttendancePunch extends Model
{
    use BelongsToTenant, BelongsToBranchScope;

    /** Alcance por sede: [columna de sede, columna de colaborador] (BelongsToBranchScope) */
    public const BRANCH_SCOPE = ['branch_id', 'user_id'];

    public const TYPES = ['clock_in', 'break_start', 'break_end', 'clock_out'];

    /** Motivos de sospecha (suspicion_reasons) */
    public const REASON_MOCK_LOCATION    = 'MOCK_LOCATION';
    public const REASON_TIME_DRIFT       = 'TIME_DRIFT';
    public const REASON_FACE_UNVERIFIED  = 'FACE_UNVERIFIED';
    public const REASON_OUT_OF_GEOFENCE  = 'OUT_OF_GEOFENCE';
    public const REASON_OUTSIDE_SCHEDULE = 'OUTSIDE_SCHEDULE';
    public const REASON_UNSAFE_DEVICE    = 'UNSAFE_DEVICE';
    /** Fuera del radio pero dentro del margen de la precisión GPS: se acepta observada */
    public const REASON_GEOFENCE_EDGE    = 'GEOFENCE_EDGE';
    /** Exigiría viajar a más de MAX_TRAVEL_KMH desde la marcación anterior */
    public const REASON_IMPOSSIBLE_TRAVEL     = 'IMPOSSIBLE_TRAVEL';
    /** Mismas coordenadas exactas que una marcación de otro día: típico de una ubicación inventada */
    public const REASON_REPEATED_COORDINATES  = 'REPEATED_COORDINATES';

    public const UPDATED_AT = null;

    protected $table = 'attendance_punches';

    protected $guarded = ['id'];

    protected $casts = [
        'latitude'               => 'float',
        'longitude'              => 'float',
        'accuracy_meters'        => 'float',
        'distance_meters'        => 'integer',
        'within_geofence'        => 'boolean',
        'device_time'            => UtcDateTime::class,
        'server_time'            => UtcDateTime::class,
        'offline_recorded_at'    => UtcDateTime::class,
        'time_drift_seconds'     => 'integer',
        'offline'                => 'boolean',
        'face_detected'          => 'boolean',
        'mock_location_detected' => 'boolean',
        'suspicious'             => 'boolean',
        'suspicion_reasons'      => 'array',
        'created_at'             => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Las marcaciones son inmutables: no se pueden editar.');
        });

        static::deleting(function () {
            throw new LogicException('Las marcaciones son inmutables: no se pueden borrar.');
        });
    }

    /**
     * Completa la dirección (geocodificación inversa). Solo si aún está vacía:
     * nunca reemplaza una dirección ya guardada.
     */
    public static function fillAddress(int $id, string $address): bool
    {
        return DB::table('attendance_punches')
            ->where('id', $id)
            ->whereNull('address')
            ->update(['address' => mb_substr($address, 0, 500)]) === 1;
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function toApi(): array
    {
        return [
            'id'                     => $this->id,
            'type'                   => $this->type,
            'client_uuid'            => $this->client_uuid,
            'attendance_id'          => $this->attendance_id,
            'server_time'            => $this->server_time?->toIso8601String(),
            'device_time'            => $this->device_time?->toIso8601String(),
            'time_drift_seconds'     => $this->time_drift_seconds,
            'offline'                => $this->offline,
            'offline_recorded_at'    => $this->offline_recorded_at?->toIso8601String(),
            'latitude'               => $this->latitude,
            'longitude'              => $this->longitude,
            'accuracy_meters'        => $this->accuracy_meters,
            'distance_meters'        => $this->distance_meters,
            'within_geofence'        => $this->within_geofence,
            'address'                => $this->address,
            'face_detected'          => $this->face_detected,
            'mock_location_detected' => $this->mock_location_detected,
            'suspicious'             => $this->suspicious,
            'suspicion_reasons'      => $this->suspicion_reasons ?? [],
            'photo_url'              => $this->photoUrl(),
        ];
    }

    /** URL firmada y temporal de la foto (o null) */
    public function photoUrl(int $minutes = 15): ?string
    {
        return $this->photo_path
            ? app(\App\Modules\Core\Services\SecureFileUrl::class)->make(config('filesystems.attendance_disk', 'private'), $this->photo_path, 'punch-photo', $this->id, $minutes)
            : null;
    }
}
