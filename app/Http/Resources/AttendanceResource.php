<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AttendanceResource — Serializa un registro de asistencia.
 * La app móvil usa esto para mostrar el estado actual de la jornada.
 */
class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'work_date'  => $this->work_date?->toDateString(),
            'status'     => $this->status,
            'status_label' => $this->getStatusLabel(),

            // Estado de la jornada
            'is_open'       => $this->isOpen(),
            'is_complete'   => $this->isComplete(),
            'is_on_break'   => $this->isOnBreak(),

            // Clock-In
            'clock_in' => $this->when($this->clock_in_at, [
                'time'                => $this->clock_in_at?->toTimeString(),
                'timestamp'           => $this->clock_in_at?->toIso8601String(),
                'latitude'            => (float) $this->clock_in_latitude,
                'longitude'           => (float) $this->clock_in_longitude,
                'distance_meters'     => $this->clock_in_distance_meters,
                'within_geofence'     => $this->clock_in_within_geofence,
                'photo_url'           => app(\App\Services\AttendanceEvidence::class)->photoUrl($this->resource, 'in'),
            ]),

            // Break
            'break' => [
                'start_time'     => $this->break_start_at?->toTimeString(),
                'end_time'       => $this->break_end_at?->toTimeString(),
                'duration_min'   => $this->break_minutes,
            ],

            // Clock-Out
            'clock_out' => $this->when($this->clock_out_at, [
                'time'            => $this->clock_out_at?->toTimeString(),
                'timestamp'       => $this->clock_out_at?->toIso8601String(),
                'latitude'        => (float) $this->clock_out_latitude,
                'longitude'       => (float) $this->clock_out_longitude,
                'distance_meters' => $this->clock_out_distance_meters,
                'within_geofence' => $this->clock_out_within_geofence,
                'photo_url'       => app(\App\Services\AttendanceEvidence::class)->photoUrl($this->resource, 'out'),
            ]),

            // Cálculos de tiempo
            'summary' => [
                'late_minutes'    => $this->late_minutes,
                'break_minutes'   => $this->break_minutes,
                'worked_hours'    => (float) $this->worked_hours,
                'overtime_hours'  => (float) $this->overtime_hours,
            ],

            // Justificación
            'justification' => [
                'status'  => $this->justification_status,
                'notes'   => $this->notes,
                'has_file'=> (bool) $this->justification_file,
            ],

            // Relaciones cargadas
            'branch' => $this->whenLoaded('branch', fn() => [
                'id'   => $this->branch->id,
                'name' => $this->branch->name,
                'code' => $this->branch->code,
            ]),

            'shift' => $this->whenLoaded('shift', fn() => [
                'id'         => $this->shift->id,
                'name'       => $this->shift->name,
                'start_time' => $this->shift->start_time,
                'end_time'   => $this->shift->end_time,
            ]),
        ];
    }

    private function getStatusLabel(): string
    {
        return match ($this->status) {
            'present'    => 'Presente',
            'late'       => 'Tardanza',
            'absent'     => 'Ausente',
            'excused'    => 'Justificado',
            'half_day'   => 'Medio Día',
            'holiday'    => 'Feriado',
            'day_off'    => 'Franco',
            'incomplete' => 'Incompleto',
            default      => 'Desconocido',
        };
    }
}
