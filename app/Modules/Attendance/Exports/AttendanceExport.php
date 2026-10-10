<?php

namespace App\Modules\Attendance\Exports;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AttendanceExport
 *
 * Genera reportes exportables en formato CSV / Excel profesional con codificación
 * UTF-8 con BOM para perfecta visualización de acentos y caracteres especiales en Microsoft Excel.
 */
class AttendanceExport
{
    protected ?int $tenantId;
    protected ?string $startDate;
    protected ?string $endDate;
    protected ?int $branchId;
    protected ?string $status;
    protected ?User $viewer;

    public function __construct(?int $tenantId, ?string $startDate = null, ?string $endDate = null, ?int $branchId = null, ?string $status = null, ?User $viewer = null)
    {
        $this->viewer    = $viewer;
        $this->tenantId  = $tenantId;
        $this->startDate = $startDate;
        $this->endDate   = $endDate;
        $this->branchId  = $branchId;
        $this->status    = $status;
    }

    /**
     * Genera la descarga en streaming directa hacia Excel.
     */
    public function download(string $fileName = 'reporte_asistencias.csv'): StreamedResponse
    {
        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        return response()->stream(function () {
            $handle = fopen('php://output', 'w');

            // Insertar UTF-8 BOM para que Excel en Windows reconozca tildes automáticamente
            fputs($handle, "\xEF\xBB\xBF");

            // Encabezados del reporte
            fputcsv($handle, [
                'ID Asistencia',
                'Fecha',
                'Código Empleado',
                'Colaborador',
                'Cargo',
                'Sucursal',
                'Turno Asignado',
                'Hora Entrada',
                'Distancia Entrada (m)',
                'Geocerca Entrada',
                'Inicio Descanso',
                'Fin Descanso',
                'Hora Salida',
                'Distancia Salida (m)',
                'Geocerca Salida',
                'Minutos Tardanza',
                'Horas Trabajadas',
                'Horas Extras',
                'Estado Asistencia',
                'Justificación',
                'Estado Justificación',
            ], ';');

            $query = Attendance::forTenant($this->tenantId)
                ->when($this->viewer, fn ($q) => $q->visibleTo($this->viewer))
                ->with(['user', 'branch', 'shift'])
                ->orderBy('work_date', 'desc')
                ->orderBy('clock_in_at', 'desc');

            if ($this->startDate && $this->endDate) {
                $query->whereBetween('work_date', [$this->startDate, $this->endDate]);
            }

            if ($this->branchId) {
                $query->where('branch_id', $this->branchId);
            }

            if ($this->status) {
                $query->where('status', $this->status);
            }

            $query->chunk(200, function ($attendances) use ($handle) {
                foreach ($attendances as $row) {
                    fputcsv($handle, [
                        $row->id,
                        $row->work_date ? $row->work_date->format('Y-m-d') : '',
                        $row->user->employee_code ?? 'N/A',
                        $row->user ? "{$row->user->first_name} {$row->user->last_name}" : 'N/A',
                        $row->user->position ?? '',
                        $row->branch->name ?? 'N/A',
                        $row->shift->name ?? 'Estándar',
                        $row->clock_in_at ? $row->clock_in_at->format('H:i:s') : 'Sin marcar',
                        $row->clock_in_distance_meters ?? '0',
                        $row->clock_in_within_geofence ? 'Dentro' : 'Fuera',
                        $row->break_start_at ? $row->break_start_at->format('H:i:s') : '',
                        $row->break_end_at ? $row->break_end_at->format('H:i:s') : '',
                        $row->clock_out_at ? $row->clock_out_at->format('H:i:s') : 'Sin marcar',
                        $row->clock_out_distance_meters ?? '0',
                        $row->clock_out_within_geofence ? 'Dentro' : 'Fuera',
                        $row->late_minutes ?? 0,
                        number_format((float) ($row->worked_hours ?? 0), 2),
                        number_format((float) ($row->overtime_hours ?? 0), 2),
                        $this->mapStatus($row->status),
                        $row->notes ?? '',
                        $row->justification_status ?? 'N/A',
                    ], ';');
                }
            });

            fclose($handle);
        }, 200, $headers);
    }

    private function mapStatus(?string $status): string
    {
        return match ($status) {
            'on_time'    => 'Puntual',
            'late'       => 'Tardanza',
            'absent'     => 'Inasistencia',
            'justified'  => 'Justificado',
            'holiday'    => 'Feriado',
            'day_off'    => 'Día Libre',
            default      => ucfirst($status ?? 'Pendiente'),
        };
    }
}
