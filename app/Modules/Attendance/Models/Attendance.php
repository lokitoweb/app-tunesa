<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\HR\Models\Shift;
use App\Modules\Core\Models\User;
use App\Modules\Core\Models\Concerns\BelongsToBranchScope;
use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\Concerns\HasDateOnlyColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo Attendance (Registro de Asistencia)
 * Core del sistema. Cada fila representa la jornada de un empleado.
 *
 * @property int    $id
 * @property int    $tenant_id
 * @property int    $user_id
 * @property int    $branch_id
 * @property string $work_date
 * @property string $status
 * @property float  $worked_hours
 */
class Attendance extends Model
{
    use BelongsToTenant, HasDateOnlyColumns, SoftDeletes, BelongsToBranchScope;

    /** Alcance por sede: [columna de sede, columna de colaborador] (BelongsToBranchScope) */
    public const BRANCH_SCOPE = ['branch_id', 'user_id'];

    /** Columnas DATE guardadas como Y-m-d (ver HasDateOnlyColumns) */
    protected array $dateOnly = ['work_date'];

    protected $fillable = [
        'tenant_id',
        'user_id',
        'branch_id',
        'shift_id',
        'work_date',

        // Clock-In
        'clock_in_at',
        'clock_in_latitude',
        'clock_in_longitude',
        'clock_in_distance_meters',
        'clock_in_photo',
        'clock_in_device_id',
        'clock_in_meta',
        'clock_in_within_geofence',

        // Break
        'break_start_at',
        'break_start_latitude',
        'break_start_longitude',
        'break_end_at',
        'break_end_latitude',
        'break_end_longitude',

        // Clock-Out
        'clock_out_at',
        'clock_out_latitude',
        'clock_out_longitude',
        'clock_out_distance_meters',
        'clock_out_photo',
        'clock_out_device_id',
        'clock_out_meta',
        'clock_out_within_geofence',

        // Integridad (resumen; el detalle está en attendance_punches)
        'clock_in_accuracy_meters',
        'clock_out_accuracy_meters',
        'clock_in_device_time',
        'clock_out_device_time',
        'clock_in_time_drift_seconds',
        'clock_out_time_drift_seconds',
        'clock_in_mock_location',
        'clock_out_mock_location',
        'clock_in_face_detected',
        'clock_out_face_detected',
        'is_suspicious',

        // Cálculos
        'status',
        'late_minutes',
        'break_minutes',
        'worked_hours',
        'overtime_hours',

        // Justificación
        'notes',
        'justification_file',
        'justification_status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'work_date'                 => 'date',
        'clock_in_at'               => 'datetime',
        'break_start_at'            => 'datetime',
        'break_end_at'              => 'datetime',
        'clock_out_at'              => 'datetime',
        'clock_in_latitude'         => 'decimal:8',
        'clock_in_longitude'        => 'decimal:8',
        'clock_out_latitude'        => 'decimal:8',
        'clock_out_longitude'       => 'decimal:8',
        'clock_in_within_geofence'  => 'boolean',
        'clock_out_within_geofence' => 'boolean',
        'clock_in_meta'             => 'array',
        'clock_out_meta'            => 'array',
        'worked_hours'              => 'decimal:2',
        'overtime_hours'            => 'decimal:2',
        'reviewed_at'               => 'datetime',
        'clock_in_accuracy_meters'  => 'float',
        'clock_out_accuracy_meters' => 'float',
        'clock_in_device_time'      => \App\Modules\Core\Casts\UtcDateTime::class,
        'clock_out_device_time'     => \App\Modules\Core\Casts\UtcDateTime::class,
        'clock_in_time_drift_seconds'  => 'integer',
        'clock_out_time_drift_seconds' => 'integer',
        'clock_in_mock_location'    => 'boolean',
        'clock_out_mock_location'   => 'boolean',
        'clock_in_face_detected'    => 'boolean',
        'clock_out_face_detected'   => 'boolean',
        'is_suspicious'             => 'boolean',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** Bitácora inmutable de marcaciones de la jornada */
    public function punches(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AttendancePunch::class)->orderBy('id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Verifica si el empleado ya marcó entrada pero no salida.
     */
    public function isOpen(): bool
    {
        return ! is_null($this->clock_in_at) && is_null($this->clock_out_at);
    }

    /**
     * Verifica si el empleado está en break actualmente.
     */
    public function isOnBreak(): bool
    {
        return ! is_null($this->break_start_at) && is_null($this->break_end_at);
    }

    /**
     * Verifica si el empleado completó su jornada.
     */
    public function isComplete(): bool
    {
        return ! is_null($this->clock_in_at) && ! is_null($this->clock_out_at);
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /** Asistencias del día actual */
    /** Marcaciones que puede ver $viewer: toda la empresa o solo su equipo */
    public function scopeVisibleTo($query, User $viewer)
    {
        if ($viewer->seesAllAttendance() || $viewer->isOrgScoped()) {
            return $query;
        }

        return $query->whereIn('user_id', User::query()->supervisedBy($viewer)->select('users.id'));
    }

    public function scopeToday($query)
    {
        return $query->whereDate('work_date', today());
    }

    /** Asistencias de un mes/año dado */
    public function scopeForPeriod($query, int $year, int $month)
    {
        return $query->whereYear('work_date', $year)
            ->whereMonth('work_date', $month);
    }

    /** Solo presentes (clock_in no nulo) */
    public function scopePresent($query)
    {
        return $query->whereNotNull('clock_in_at');
    }

    /** Empleados con tardanza */
    public function scopeLate($query)
    {
        return $query->where('late_minutes', '>', 0);
    }
}
