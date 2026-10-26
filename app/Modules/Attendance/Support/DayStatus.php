<?php

namespace App\Modules\Attendance\Support;

/**
 * Estado de un día para mostrar (QA Ronda 2, RP-03): un solo mapper y una sola fuente
 * de etiquetas (lang/es/attendance.php). No toca el enum attendances.status: Descanso,
 * Vacaciones, Permiso y Descanso médico se derivan de la programación y las licencias.
 */
final class DayStatus
{
    public const NORMAL = 'normal';
    public const LATE = 'late';
    public const ABSENT = 'absent';
    public const INCOMPLETE = 'incomplete';
    public const REST = 'rest';
    public const VACATION = 'vacation';
    public const PERMISSION = 'permission';
    public const MEDICAL_LEAVE = 'medical_leave';
    public const EXCUSED = 'excused';
    public const HOLIDAY = 'holiday';
    public const HALF_DAY = 'half_day';
    public const SCHEDULED = 'scheduled';
    /** Regla vigente 7: marcó la salida antes de la salida oficial del horario */
    public const EARLY_EXIT = 'early_exit';
    /** Regla vigente 8: asistió en un feriado (no es falta) */
    public const HOLIDAY_WORKED = 'holiday_worked';

    /** Letra corta para celdas e impresión */
    public const LETTERS = [
        self::NORMAL => 'N', self::LATE => 'T', self::ABSENT => 'F', self::INCOMPLETE => 'I',
        self::REST => 'D', self::VACATION => 'V', self::PERMISSION => 'P', self::MEDICAL_LEAVE => 'DM',
        self::EXCUSED => 'J', self::HOLIDAY => 'FE', self::HALF_DAY => 'M', self::SCHEDULED => '–', self::EARLY_EXIT => 'SA', self::HOLIDAY_WORKED => 'FT',
    ];

    public static function label(string $status): string
    {
        // La app solo existe en español: no depende de APP_LOCALE
        return __("attendance.status.{$status}", [], 'es');
    }

    public static function letter(string $status): string
    {
        return self::LETTERS[$status] ?? '?';
    }

    /** Estado guardado en attendances.status → estado para mostrar */
    public static function fromRecord(?string $status): string
    {
        return match ($status) {
            'late'       => self::LATE,
            'absent'     => self::ABSENT,
            'excused'    => self::EXCUSED,
            'half_day'   => self::HALF_DAY,
            'holiday'    => self::HOLIDAY,
            'day_off'    => self::REST,
            'incomplete' => self::INCOMPLETE,
            default      => self::NORMAL,
        };
    }

    /** Ausencia cubierta por una licencia aprobada (LeaveRequest::TYPES) */
    public static function fromLeave(?string $leaveType): string
    {
        return match ($leaveType) {
            'vacation'      => self::VACATION,
            'permission'    => self::PERMISSION,
            'medical_leave' => self::MEDICAL_LEAVE,
            default         => self::EXCUSED,
        };
    }
}
