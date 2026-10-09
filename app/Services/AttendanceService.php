<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\EmployeeShift;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * AttendanceService
 *
 * Centraliza toda la lógica de negocio de las asistencias.
 * Los controladores solo delegan aquí; ellos no contienen lógica.
 *
 * Responsabilidades:
 *  - Obtener o crear el registro de asistencia del día
 *  - Procesar Clock-In (validación GPS + foto + cálculo de tardanza)
 *  - Procesar Break Start / Break End
 *  - Procesar Clock-Out (validación GPS + foto + cálculo de horas)
 *  - Calcular horas trabajadas y horas extra
 */
class AttendanceService
{
    public function __construct(
        private readonly GeofenceService $geofence,
        private readonly TenantContext   $tenantContext,
    ) {}

    // =========================================================================
    // CLOCK-IN (ENTRADA)
    // =========================================================================

    /**
     * Registra la entrada del empleado.
     *
     * @throws \Exception Si ya marcó entrada hoy, si está fuera de geocerca, etc.
     */
    public function clockIn(
        User          $employee,
        float         $latitude,
        float         $longitude,
        UploadedFile  $photo,
        string        $deviceId,
        array         $meta = [],
    ): Attendance {

        $tenant  = $this->tenantContext->get();
        $today   = Carbon::now($tenant->timezone)->toDateString();

        // 1. Verificar si ya existe un registro abierto para hoy
        $existing = Attendance::where('user_id', $employee->id)
            ->where('work_date', $today)
            ->first();

        if ($existing && ! is_null($existing->clock_in_at)) {
            throw new \Exception(
                'Ya registraste tu entrada hoy. '
                . ($existing->clock_out_at ? 'Tu jornada está completa.' : 'Tu jornada está en curso.'),
                409
            );
        }

        // 2. Obtener la sucursal activa del empleado para hoy
        $branch = $this->getEmployeeBranchForToday($employee, $today);

        // 3. Validar geocerca con Haversine
        $geofenceResult = $this->geofence->check($latitude, $longitude, $branch);

        if (! $geofenceResult->isWithinGeofence) {
            throw new \Exception($geofenceResult->getMessage(), 422);
        }

        // 4. Obtener el turno activo
        $employeeShift = $this->getCurrentShift($employee, $today);
        $shift = $employeeShift?->shift;

        // 5. Guardar la foto selfie en Storage
        $photoPath = $this->storePhoto($photo, $employee->tenant_id, $employee->id, 'clock_in');

        // 6. Calcular tardanza
        $lateMinutes = $this->calculateLateMinutes($shift, $today, $tenant->timezone);

        // 7. Crear el registro de asistencia
        $attendance = Attendance::create([
            'tenant_id'                => $employee->tenant_id,
            'user_id'                  => $employee->id,
            'branch_id'                => $branch->id,
            'shift_id'                 => $shift?->id,
            'work_date'                => $today,

            'clock_in_at'              => Carbon::now($tenant->timezone),
            'clock_in_latitude'        => $latitude,
            'clock_in_longitude'       => $longitude,
            'clock_in_distance_meters' => $geofenceResult->distanceMeters,
            'clock_in_photo'           => $photoPath,
            'clock_in_device_id'       => $deviceId,
            'clock_in_within_geofence' => true,
            'clock_in_meta'            => array_merge($meta, [
                'geofence' => $geofenceResult->toArray(),
                'recorded_at' => now()->toIso8601String(),
            ]),

            'status'       => $lateMinutes > 0 ? 'late' : 'present',
            'late_minutes' => $lateMinutes,
        ]);

        return $attendance->load(['branch', 'shift']);
    }

    // =========================================================================
    // BREAK START (INICIO DE DESCANSO)
    // =========================================================================

    /**
     * Registra el inicio del descanso/lunch.
     */
    public function breakStart(
        User   $employee,
        float  $latitude,
        float  $longitude,
    ): Attendance {

        $attendance = $this->getOpenAttendanceOrFail($employee);

        if (! is_null($attendance->break_start_at)) {
            throw new \Exception('Ya iniciaste tu descanso.', 409);
        }

        $shift = $attendance->shift;
        if ($shift && ! $shift->break_allowed) {
            throw new \Exception('Tu turno no permite descansos.', 422);
        }

        $attendance->update([
            'break_start_at'        => now(tenant_timezone()),
            'break_start_latitude'  => $latitude,
            'break_start_longitude' => $longitude,
        ]);

        return $attendance->fresh();
    }

    // =========================================================================
    // BREAK END (FIN DE DESCANSO)
    // =========================================================================

    /**
     * Registra el regreso del descanso.
     */
    public function breakEnd(
        User  $employee,
        float $latitude,
        float $longitude,
    ): Attendance {

        $attendance = $this->getOpenAttendanceOrFail($employee);

        if (is_null($attendance->break_start_at)) {
            throw new \Exception('No has iniciado un descanso.', 409);
        }

        if (! is_null($attendance->break_end_at)) {
            throw new \Exception('Ya finalizaste tu descanso.', 409);
        }

        // Calcular duración del break
        $breakMinutes = (int) $attendance->break_start_at->diffInMinutes(now());

        // Verificar que no exceda el límite del turno
        $shift = $attendance->shift;
        if ($shift && $shift->break_allowed && $breakMinutes > $shift->break_duration_minutes) {
            // Registrar igual pero se marcará en el reporte como "break excedido"
            logger()->info("Break excedido", [
                'user_id'       => $employee->id,
                'limit_minutes' => $shift->break_duration_minutes,
                'taken_minutes' => $breakMinutes,
            ]);
        }

        $attendance->update([
            'break_end_at'        => now(tenant_timezone()),
            'break_end_latitude'  => $latitude,
            'break_end_longitude' => $longitude,
            'break_minutes'       => $breakMinutes,
        ]);

        return $attendance->fresh();
    }

    // =========================================================================
    // CLOCK-OUT (SALIDA)
    // =========================================================================

    /**
     * Registra la salida del empleado y calcula horas trabajadas.
     */
    public function clockOut(
        User         $employee,
        float        $latitude,
        float        $longitude,
        UploadedFile $photo,
        string       $deviceId,
        array        $meta = [],
    ): Attendance {

        $attendance = $this->getOpenAttendanceOrFail($employee);

        // 1. Validar geocerca de salida
        $branch         = $attendance->branch;
        $geofenceResult = $this->geofence->check($latitude, $longitude, $branch);

        if (! $geofenceResult->isWithinGeofence) {
            throw new \Exception($geofenceResult->getMessage(), 422);
        }

        // 2. Foto de salida
        $photoPath = $this->storePhoto($photo, $employee->tenant_id, $employee->id, 'clock_out');

        // 3. Calcular horas trabajadas
        $clockOutTime  = Carbon::now(tenant_timezone());
        $clockInTime   = $attendance->clock_in_at;
        $breakMinutes  = $attendance->break_minutes ?? 0;

        $totalMinutes  = $clockInTime->diffInMinutes($clockOutTime);
        $netMinutes    = max(0, $totalMinutes - $breakMinutes);
        $workedHours   = round($netMinutes / 60, 2);

        // 4. Calcular horas extra
        $shift          = $attendance->shift;
        $dailyHours     = $shift ? (float) $shift->daily_hours : 8.0;
        $overtimeHours  = max(0, round($workedHours - $dailyHours, 2));

        // 5. Determinar estado final
        $finalStatus = $this->determineFinalStatus($attendance, $workedHours, $dailyHours);

        // 6. Actualizar el registro
        $attendance->update([
            'clock_out_at'               => $clockOutTime,
            'clock_out_latitude'         => $latitude,
            'clock_out_longitude'        => $longitude,
            'clock_out_distance_meters'  => $geofenceResult->distanceMeters,
            'clock_out_photo'            => $photoPath,
            'clock_out_device_id'        => $deviceId,
            'clock_out_within_geofence'  => true,
            'clock_out_meta'             => array_merge($meta, [
                'geofence'    => $geofenceResult->toArray(),
                'recorded_at' => $clockOutTime->toIso8601String(),
            ]),
            'worked_hours'   => $workedHours,
            'overtime_hours' => $overtimeHours,
            'status'         => $finalStatus,
        ]);

        return $attendance->fresh()->load(['branch', 'shift', 'user']);
    }

    // =========================================================================
    // HELPERS PRIVADOS
    // =========================================================================

    /**
     * Obtiene el registro de asistencia abierto del día de hoy.
     * Lanza excepción si no existe o ya está cerrado.
     */
    private function getOpenAttendanceOrFail(User $employee): Attendance
    {
        $today = Carbon::now(tenant_timezone())->toDateString();

        $attendance = Attendance::where('user_id', $employee->id)
            ->where('work_date', $today)
            ->whereNotNull('clock_in_at')
            ->whereNull('clock_out_at')
            ->first();

        if (! $attendance) {
            throw new \Exception(
                'No tienes una jornada abierta hoy. Primero debes marcar tu entrada.',
                404
            );
        }

        return $attendance;
    }

    /**
     * Obtiene la sucursal asignada al empleado para el día de hoy.
     * Busca la asignación vigente más reciente.
     */
    private function getEmployeeBranchForToday(User $employee, string $date): Branch
    {
        // Buscar desde la tabla pivot employee_branch, sucursal principal primero
        $branch = $employee->branches()
            ->wherePivot('assigned_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('employee_branch.assigned_until')
                    ->orWhere('employee_branch.assigned_until', '>=', $date);
            })
            ->orderByPivot('is_primary', 'desc')
            ->where('branches.is_active', true)
            ->first();

        if (! $branch) {
            throw new \Exception(
                'No tienes ninguna sucursal asignada. Contacta a RRHH.',
                422
            );
        }

        return $branch;
    }

    /**
     * Obtiene la asignación de turno activa para un empleado en una fecha.
     */
    private function getCurrentShift(User $employee, string $date): ?EmployeeShift
    {
        return EmployeeShift::where('user_id', $employee->id)
            ->current()
            ->first();
    }

    /**
     * Calcula los minutos de tardanza comparando la hora actual con el
     * horario de entrada programado del turno (más el período de gracia).
     */
    private function calculateLateMinutes(?Shift $shift, string $date, string $timezone): int
    {
        if (! $shift) {
            return 0; // Sin turno asignado = no se calcula tardanza
        }

        $now = Carbon::now($timezone);

        // Hora límite de entrada = hora_inicio + período de gracia
        $expectedEntry = Carbon::parse("{$date} {$shift->start_time}", $timezone)
            ->addMinutes($shift->grace_period_minutes);

        if ($now->lessThanOrEqualTo($expectedEntry)) {
            return 0; // Llegó a tiempo (dentro del período de gracia)
        }

        // Minutos de tardanza = diferencia desde la hora de entrada programada (sin gracia)
        $scheduledEntry = Carbon::parse("{$date} {$shift->start_time}", $timezone);
        return (int) $scheduledEntry->diffInMinutes($now);
    }

    /**
     * Determina el estado final de la asistencia al hacer clock-out.
     */
    private function determineFinalStatus(
        Attendance $attendance,
        float      $workedHours,
        float      $dailyHours,
    ): string {

        // Si ya marcó tardanza, mantener ese estado
        if ($attendance->status === 'late') {
            return 'late';
        }

        // Medio día: trabajó menos del 60% de las horas esperadas
        if ($workedHours < ($dailyHours * 0.60)) {
            return 'half_day';
        }

        return 'present';
    }

    /**
     * Guarda la foto selfie en Storage y retorna la ruta relativa.
     * Estructura: photos/{tenant_id}/{user_id}/{type}/{year}/{month}/{timestamp}.jpg
     */
    private function storePhoto(
        UploadedFile $photo,
        int          $tenantId,
        int          $userId,
        string       $type, // 'clock_in' | 'clock_out'
    ): string {

        $year  = now()->format('Y');
        $month = now()->format('m');
        $ts    = now()->format('YmdHis');

        $directory = "photos/{$tenantId}/{$userId}/{$type}/{$year}/{$month}";
        $filename  = "{$ts}_{$userId}.jpg";

        // Storage::disk('public') o 's3' según config
        return $photo->storeAs($directory, $filename, config('filesystems.attendance_disk', 'public'));
    }
}
