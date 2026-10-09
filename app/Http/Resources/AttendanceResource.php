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
                'photo_url'           => $this->clock_in_photo
                    ? Storage_url($this->clock_in_photo)
                    : null,
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
                'photo_url'       => $this->clock_out_photo
                    ? Storage_url($this->clock_out_photo)
                    : null,
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
                'file_url'=> $this->justification_file
                    ? Storage_url($this->justification_file)
                    : null,
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

/**
 * Helper para generar URL pública de Storage.
 * Soporta disco 'public' (local) y 's3'.
 */
function Storage_url(string $path): string
{
    $disk = config('filesystems.attendance_disk', 'public');
    return \Illuminate\Support\Facades\Storage::disk($disk)->url($path);
}
