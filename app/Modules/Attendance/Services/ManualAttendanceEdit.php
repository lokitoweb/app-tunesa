<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Corrección manual de una jornada desde el panel (permiso attendance.edit).
 *
 * RRHH y administradores (attendance.view_all) corrigen a cualquiera de la
 * empresa; un supervisor, solo a su equipo. Nadie se corrige a sí mismo.
 * Las horas se recalculan con las mismas reglas que al marcar desde la app
 * (descanso fijo, jornada base, horas extra por aprobar) y el antes y después
 * queda en la bitácora con el motivo.
 */
class ManualAttendanceEdit
{
    private const AUDITED = ['clock_in_at', 'break_start_at', 'break_end_at', 'clock_out_at', 'break_minutes', 'break_late_minutes', 'punches_incomplete',
        'worked_hours', 'overtime_hours', 'late_minutes', 'status', 'shift_id'];

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AuditLogger $audit,
    ) {}

    public function canEdit(User $editor, User $employee): bool
    {
        if (! $editor->can('attendance.edit') || (int) $editor->id === (int) $employee->id) {
            return false;
        }

        return $editor->can('attendance.view_all')
            || User::whereKey($employee->id)->visibleTo($editor)->exists();
    }

    public function find(User $employee, string $date): ?Attendance
    {
        return Attendance::where('user_id', $employee->id)->where('work_date', $date)->orderBy('id')->first();
    }

    /** Horario de ese día: el de la jornada registrada o el programado (con sus horas por día de la semana) */
    public function shiftFor(User $employee, string $date, ?Attendance $record): ?\App\Modules\HR\Models\Shift
    {
        [$assignment] = $this->attendance->getCurrentShift($employee, $date);

        return ($record?->shift ?? $assignment?->shift)?->forDate($date);
    }

    /**
     * @param  string|null  $clockIn     HH:MM (24 h); vacío = no vino (falta)
     * @param  string|null  $clockOut    HH:MM (24 h); menor o igual que la entrada = día siguiente
     * @param  string|null  $breakStart  Salida a refrigerio HH:MM (las dos horas o ninguna)
     * @param  string|null  $breakEnd    Retorno del refrigerio HH:MM
     *
     * @throws ValidationException
     */
    public function save(User $editor, User $employee, string $date, ?string $clockIn, ?string $clockOut, ?int $breakMinutes, string $reason,
        ?string $breakStart = null, ?string $breakEnd = null): Attendance
    {
        // null en ambos = no se enviaron: se conserva el refrigerio ya marcado en la app; '' = quitarlo
        $keepBreak = null;
        if ($breakStart === null && $breakEnd === null && ($existing = $this->find($employee, $date))?->break_start_at) {
            if ($existing->break_end_at) {
                [$breakStart, $breakEnd] = [$existing->break_start_at->format('H:i'), $existing->break_end_at->format('H:i')];
            } else {
                $keepBreak = $existing->break_start_at; // salió a refrigerio y no marcó el retorno: se deja tal cual
            }
        }
        $breakStart = $breakStart ?: null;
        $breakEnd = $breakEnd ?: null;

        if ((bool) $breakStart !== (bool) $breakEnd) {
            throw ValidationException::withMessages([$breakStart ? 'break_end' : 'break_start' => 'Indica la salida y el retorno del refrigerio, o deja ambas vacías.']);
        }
        if ($breakStart && ! $clockIn) {
            throw ValidationException::withMessages(['clock_in' => 'Indica la hora de entrada.']);
        }
        if (! $this->canEdit($editor, $employee)) {
            throw ValidationException::withMessages(['reason' => 'No puedes corregir la asistencia de esta persona.']);
        }
        if (Carbon::parse($date)->isAfter(now(tenant_timezone())->endOfDay())) {
            throw ValidationException::withMessages(['date' => 'No se puede registrar asistencia de días que aún no llegan.']);
        }
        if (! $clockIn && $clockOut) {
            throw ValidationException::withMessages(['clock_in' => 'Indica la hora de entrada.']);
        }

        $tz = tenant_timezone();
        $record = $this->find($employee, $date);
        $before = $record ? $this->snapshot($record) : [];
        [$assignment, , $reference] = $this->attendance->getCurrentShift($employee, $date);
        $shift = ($record?->shift ?? $assignment?->shift)?->forDate($date);

        $values = [
            'shift_id'      => $shift?->id,
            'clock_in_at'   => null,
            'break_start_at' => null,
            'break_end_at'  => null,
            'clock_out_at'  => null,
            'break_minutes' => 0,
            'break_late_minutes' => 0,
            'punches_incomplete' => false,
            'worked_hours'  => 0,
            'overtime_hours' => 0,
            'late_minutes'  => 0,
            'status'        => 'absent',
        ];

        if ($clockIn) {
            $in = Carbon::parse("{$date} {$clockIn}", $tz);
            // Las horas siguientes que quedan antes de la entrada son del día siguiente (turno nocturno)
            $after = function (string $hhmm, Carbon $from) use ($date, $tz): Carbon {
                $at = Carbon::parse("{$date} {$hhmm}", $tz);

                return $at->lte($from) ? $at->addDay() : $at;
            };
            $late = $this->attendance->calculateLateMinutes($shift, $date, $tz, $in);
            $values = array_merge($values, [
                'clock_in_at'   => $in->format('Y-m-d H:i:s'),
                'late_minutes'  => $late,
                'status'        => $clockOut ? null : 'incomplete',
                'break_minutes' => max(0, (int) $breakMinutes),
            ]);

            // Refrigerio: salida y retorno en orden; los minutos y el retorno tardío salen de las horas, igual que en la app
            if ($breakStart) {
                $bs = $after($breakStart, $in);
                $be = $after($breakEnd, $bs);
                if ($bs->diffInHours($in, true) >= 24 || $be->diffInHours($in, true) >= 24) {
                    throw ValidationException::withMessages(['break_start' => 'El refrigerio debe estar dentro de la jornada.']);
                }
                $values = array_merge($values, [
                    'break_start_at'     => $bs->format('Y-m-d H:i:s'),
                    'break_end_at'       => $be->format('Y-m-d H:i:s'),
                    'break_minutes'      => (int) $bs->diffInMinutes($be),
                    'break_late_minutes' => $this->attendance->calculateBreakLateMinutes($shift, $date, $tz, $be),
                ]);
            } elseif ($keepBreak) {
                $values['break_start_at'] = $keepBreak->format('Y-m-d H:i:s');
            }

            if ($clockOut) {
                $out = Carbon::parse("{$date} {$clockOut}", $tz);
                if ($out->lte($in)) {
                    $out->addDay();
                }
                if (isset($be) && $out->lte($be)) {
                    throw ValidationException::withMessages(['clock_out' => 'La salida debe ser después del retorno del refrigerio.']);
                }
                $total = (int) $in->diffInMinutes($out);
                $settings = TenantSetting::forTenant($employee->tenant);
                $deduct = $settings->lunch_paid ? 0 : $this->attendance->breakToDeduct($total, (int) $values['break_minutes'], $shift, $settings);
                $worked = round(max(0, $total - $deduct) / 60, 2);
                $base = AttendanceService::baseDailyHours($shift, $employee);
                $overtime = $this->attendance->overtimeHours($shift, $date, $tz, $out, $worked, $base, (bool) $employee->tenant?->setting('flags.hours_v2'));

                $values['clock_out_at']   = $out->format('Y-m-d H:i:s');
                $values['worked_hours']   = $worked;
                $values['overtime_hours'] = $overtime;
                $values['status'] = $late > 0 ? 'late' : ($worked < $base * 0.60 ? 'half_day' : 'present');
                // Igual que al marcar salida en la app: horario de 4 marcaciones sin el refrigerio completo
                $values['punches_incomplete'] = (bool) $shift?->hasBreakWindow() && ! $breakStart;
            }
        }

        // Si cambian las horas extra, vuelven a quedar por aprobar
        $overtimeChanged = ! $record || (float) $record->overtime_hours !== (float) $values['overtime_hours'];
        if ($overtimeChanged) {
            $values += [
                'overtime_status' => Attendance::overtimeStatusFor((float) $values['overtime_hours']),
                'overtime_approved_hours' => null, 'overtime_reviewed_by' => null, 'overtime_reviewed_at' => null, 'overtime_review_notes' => null,
            ];
        }

        $values += [
            'manual_edited_by'   => $editor->id,
            'manual_edited_at'   => now(),
            'manual_edit_reason' => mb_substr(trim($reason), 0, 500),
        ];

        if ($record) {
            $record->update($values);
        } else {
            $branchId = $reference?->branch_id ?? $employee->primaryBranch()->value('branches.id') ?? $employee->branches()->value('branches.id');
            if (! $branchId) {
                throw ValidationException::withMessages(['reason' => 'Esta persona no tiene sucursal asignada.']);
            }
            $record = Attendance::create($values + [
                'tenant_id' => $employee->tenant_id, 'user_id' => $employee->id, 'branch_id' => $branchId, 'work_date' => $date,
            ]);
        }

        $this->audit->logChanges('attendance.manual_edit', $record, $before, $this->snapshot($record->fresh()), [
            'employee_id' => $employee->id,
            'work_date'   => $date,
            'reason'      => $values['manual_edit_reason'],
        ]);

        return $record;
    }

    private function snapshot(Attendance $a): array
    {
        $row = $a->only(self::AUDITED);
        foreach (['clock_in_at', 'break_start_at', 'break_end_at', 'clock_out_at'] as $k) {
            $row[$k] = $a->{$k}?->format('Y-m-d H:i');
        }

        return $row;
    }
}
