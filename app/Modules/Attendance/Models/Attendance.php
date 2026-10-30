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

    /** Horas extra: estados de aprobación por jefatura */
    public const OVERTIME_PENDING = 'pending';

    public const OVERTIME_APPROVED = 'approved';

    public const OVERTIME_REJECTED = 'rejected';

    /** Desde cuántas horas extra en el día se piden a aprobación (15 min) */
    public const OVERTIME_MIN_HOURS = 0.25;

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
        'break_late_minutes',
        'punches_incomplete',
        'worked_hours',
        'overtime_hours',

        // Aprobación de horas extra
        'overtime_status',
        'overtime_approved_hours',
        'overtime_reviewed_by',
        'overtime_reviewed_at',
        'overtime_review_notes',

        // Corrección manual desde el panel
        'manual_edited_by',
        'manual_edited_at',
        'manual_edit_reason',

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
        'break_late_minutes'        => 'integer',
        'punches_incomplete'        => 'boolean',
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
        'overtime_approved_hours'   => 'decimal:2',
        'overtime_reviewed_at'      => 'datetime',
        'manual_edited_at'          => 'datetime',
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

    public function overtimeReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overtime_reviewed_by');
    }

    public function manualEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manual_edited_by');
    }

    /**
     * Estado inicial de las horas extra al cerrar la jornada: por aprobar desde
     * OVERTIME_MIN_HOURS; por debajo no se piden (unos minutos de más al salir).
     */
    public static function overtimeStatusFor(float $overtimeHours): ?string
    {
        return $overtimeHours >= self::OVERTIME_MIN_HOURS ? self::OVERTIME_PENDING : null;
    }

    /** Horas extra que van a planilla: solo las aprobadas */
    public function payableOvertimeHours(): float
    {
        return $this->overtime_status === self::OVERTIME_APPROVED ? (float) $this->overtime_approved_hours : 0.0;
    }

    /** Lo mismo en SQL, para sumas por colaborador (planilla, reportes) */
    public static function payableOvertimeSql(string $table = 'attendances'): string
    {
        return "CASE WHEN {$table}.overtime_status = 'approved' THEN COALESCE({$table}.overtime_approved_hours, 0) ELSE 0 END";
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Horas de jornada: lo trabajado con la jornada del turno como tope (p. ej.
     * 8 h aunque se quede más). El exceso son horas extra y solo cuentan si se
     * aprueban (payableHours).
     */
    public function regularHours(): float
    {
        return round(max(0, (float) $this->worked_hours - (float) $this->overtime_hours), 2);
    }

    /** Horas a pagar del día: jornada + horas extra aprobadas (igual que la planilla) */
    public function payableHours(): float
    {
        $approved = $this->overtime_status === self::OVERTIME_APPROVED ? (float) $this->overtime_approved_hours : 0.0;

        return round($this->regularHours() + $approved, 2);
    }

    /**
     * Tardanza desglosada en horas, minutos y segundos respetando espacios ("3 h 51 min 14 s tarde").
     */
    public function formattedLateDuration(bool $showSeconds = true): ?string
    {
        return formato_tardanza($this, $showSeconds);
    }

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

    /**
     * El colaborador justifica el día (tardanza, falta, marca olvidada): queda pendiente de
     * revisión. La app y el portal web usan esta misma regla. Una ya aprobada no se reemplaza.
     */
    public function submitJustification(string $notes, ?\Illuminate\Http\UploadedFile $file = null): void
    {
        if ($this->justification_status === 'approved') {
            throw \Illuminate\Validation\ValidationException::withMessages(['notes' => 'Esta justificación ya fue aprobada.']);
        }

        $data = ['notes' => $notes, 'justification_status' => 'pending', 'reviewed_by' => null, 'reviewed_at' => null];
        if ($file) {
            $data['justification_file'] = $file->store("justifications/{$this->tenant_id}/{$this->user_id}", 'private');
        }

        $this->update($data);
    }

    /**
     * Aprueba o rechaza la justificación (panel y API usan esta misma regla).
     * Solo si está pendiente; la nota del revisor se agrega sin borrar lo que escribió el colaborador.
     */
    public function reviewJustification(User $reviewer, string $decision, ?string $note = null): void
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException("Decisión inválida: {$decision}");
        }
        if ($this->justification_status !== 'pending') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'justification_status' => 'Esta justificación ya fue revisada o no se envió.',
            ]);
        }

        $this->update([
            'justification_status' => $decision,
            'status'               => $decision === 'approved' ? 'excused' : $this->status,
            'reviewed_by'          => $reviewer->id,
            'reviewed_at'          => now(),
            'notes'                => ($this->notes ? $this->notes . "\n---\n" : '')
                . "Revisión: {$decision}. Nota: " . (filled($note) ? $note : 'Sin comentarios adicionales.'),
        ]);
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
