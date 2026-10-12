<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\TenantContext;
use App\Modules\Attendance\Exceptions\AttendanceException;
use App\Modules\Attendance\Jobs\ReverseGeocodePunch;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Core\Models\Branch;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Shift;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\Attendance\Services\Attendance\AttendanceAlertNotifier;
use App\Modules\Attendance\Services\Attendance\PunchAttemptRecorder;
use App\Modules\Attendance\Services\Attendance\PunchData;
use App\Modules\Attendance\Services\Attendance\PunchResult;
use App\Modules\Attendance\Services\Attendance\SelfieStorage;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * AttendanceService
 *
 * Toda marcación (entrada, inicio/fin de descanso, salida), venga de un
 * endpoint individual, de sync-batch o de otro camino, entra por punch():
 *
 *  1. Idempotencia: el mismo client_uuid en la empresa devuelve el resultado
 *     existente (duplicate = true) y nunca crea dos veces.
 *  2. Controles: GPS simulado (mock_location_policy), precisión GPS, rostro,
 *     ventana offline, desfase de reloj (max_time_drift_seconds).
 *  3. En UNA transacción: el cambio en `attendances` + la fila inmutable de
 *     `attendance_punches` (con motivos de sospecha).
 *  4. Después del commit: geocodificación inversa (job) y avisos
 *     (fuera de horario / fuera de geocerca).
 *
 * Las columnas *_at de `attendances` guardan la hora de pared de la empresa;
 * la bitácora guarda horas UTC.
 */
class AttendanceService
{
    private const TYPE_ORDER = ['clock_in' => 0, 'break_start' => 1, 'break_end' => 2, 'clock_out' => 3];

    /** Velocidad máxima creíble entre dos marcaciones (km/h) y distancia mínima para medirla (m) */
    private const MAX_TRAVEL_KMH    = 150;
    private const MIN_TRAVEL_METERS = 2000;

    public function __construct(
        private readonly GeofenceService         $geofence,
        private readonly TenantContext           $tenantContext,
        private readonly AuditLogger             $audit,
        private readonly SelfieStorage           $selfies,
        private readonly AttendanceAlertNotifier $alerts,
        private readonly PunchAttemptRecorder    $attempts,
    ) {}

    // =========================================================================
    // PUNTO DE ENTRADA ÚNICO
    // =========================================================================

    /**
     * Un rechazo por regla de negocio (422: geocerca, ubicación simulada, GPS,
     * sin conexión...) queda en attendance_punch_attempts. Los conflictos de
     * estado (409/404: ya marcó, no hay jornada abierta) no son intentos
     * rechazados, son reintentos, y no se registran.
     *
     * @throws AttendanceException
     */
    public function punch(User $employee, PunchData $data): PunchResult
    {
        try {
            return $this->attemptPunch($employee, $data);
        } catch (AttendanceException $e) {
            if ($e->status() === 422) {
                $this->attempts->record($employee, $data, $e);
            }

            throw $e;
        }
    }

    private function attemptPunch(User $employee, PunchData $data): PunchResult
    {
        $tenant   = $this->tenantContext->get();
        $settings = TenantSetting::forTenant($tenant);

        if ($data->clientUuid && ($existing = $this->findDuplicate($employee, $data->clientUuid))) {
            return $existing;
        }

        $serverTime = CarbonImmutable::now('UTC');
        $reasons    = [];

        $this->enforceDeviceTrust($employee, $data, $settings, $reasons);
        $at    = $this->resolveTime($data, $settings, $tenant);
        $reasons = array_merge($reasons, $this->locationReasons($employee, $data, $serverTime));
        $drift = $this->timeDrift($data, $serverTime);

        if ($drift !== null && abs($drift) > $settings->maxTimeDriftSeconds()) {
            $reasons[] = AttendancePunch::REASON_TIME_DRIFT;
        }

        $photoPath = $data->photo
            ? $this->selfies->store($data->photo, $employee->tenant_id, $employee->id, $data->type)
            : null;

        $context = [
            'employee'   => $employee,
            'data'       => $data,
            'settings'   => $settings,
            'tenant'     => $tenant,
            'at'         => $at,
            'serverTime' => $serverTime,
            'drift'      => $drift,
            'photoPath'  => $photoPath,
        ];

        try {
            /** @var PunchResult $result */
            $result = DB::transaction(fn () => $this->recordInTransaction($context, $reasons));
        } catch (UniqueConstraintViolationException $e) {
            $this->deletePhoto($photoPath);

            // Carrera: otra petición con el mismo client_uuid ganó
            if ($data->clientUuid && ($existing = $this->findDuplicate($employee, $data->clientUuid))) {
                return $existing;
            }

            // Carrera de dos entradas a la vez (índice único de attendances)
            if ($data->type === 'clock_in') {
                throw new AttendanceException('Ya registraste tu entrada hoy. Tu jornada está en curso.', 'ALREADY_CLOCKED_IN', 409);
            }

            throw $e;
        } catch (AttendanceException $e) {
            $this->deletePhoto($photoPath);

            if ($e->errorCode === 'GEOFENCE_VIOLATION') {
                $this->alerts->outsideGeofence($employee, $e->context, $data->type, rejected: true);
            }

            throw $e;
        } catch (\Throwable $e) {
            $this->deletePhoto($photoPath);

            throw $e;
        }

        $this->afterCommit($result);

        return $result;
    }

    // =========================================================================
    // TRANSACCIÓN: jornada + bitácora
    // =========================================================================

    private function recordInTransaction(array $c, array $reasons): PunchResult
    {
        /** @var PunchData $data */
        $data = $c['data'];

        [$attendance, $branch, $geo, $reasons] = match ($data->type) {
            'clock_in'    => $this->applyClockIn($c, $reasons),
            'break_start' => $this->applyBreakStart($c, $reasons),
            'break_end'   => $this->applyBreakEnd($c, $reasons),
            'clock_out'   => $this->applyClockOut($c, $reasons),
        };

        $reasons = array_values(array_unique($reasons));

        $punch = AttendancePunch::create([
            'tenant_id'              => $attendance->tenant_id,
            'attendance_id'          => $attendance->id,
            'user_id'                => $attendance->user_id,
            'branch_id'              => $branch?->id,
            'type'                   => $data->type,
            'client_uuid'            => $data->clientUuid,
            'latitude'               => $data->latitude,
            'longitude'              => $data->longitude,
            'accuracy_meters'        => $data->accuracyMeters,
            'distance_meters'        => $geo?->distanceMeters,
            'within_geofence'        => $geo?->isWithinGeofence,
            'device_time'            => $data->deviceTime,
            'server_time'            => $c['serverTime'],
            'time_drift_seconds'     => $c['drift'],
            'offline'                => $data->isOffline(),
            'offline_recorded_at'    => $data->offlineRecordedAt,
            'photo_path'             => $c['photoPath'],
            'face_detected'          => $data->faceDetected,
            'mock_location_detected' => $data->isMocked,
            'suspicious'             => $reasons !== [],
            'suspicion_reasons'      => $reasons,
            'device_id'              => $data->deviceId,
            'device_os'              => $data->deviceOs,
            'app_version'            => $data->appVersion,
            'ip'                     => $data->ip,
        ]);

        if ($reasons !== [] && ! $attendance->is_suspicious) {
            $attendance->is_suspicious = true;
            $attendance->save();
        }

        if ($data->isOffline()) {
            $this->audit->log('attendance.offline_synced', $attendance, [
                'moment'      => $data->type,
                'punch_id'    => $punch->id,
                'recorded_at' => $c['at']->toIso8601String(),
                'synced_at'   => now()->toIso8601String(),
            ]);
        }

        if ($geo && ! $geo->isWithinGeofence && ! $geo->isEdge && $data->isClockEvent()) {
            $this->audit->log("attendance.{$data->type}_out_of_geofence", $attendance, $geo->toArray());
        }

        return new PunchResult($attendance->fresh()->load(['branch', 'shift']), $punch);
    }

    /** @return array{0: Attendance, 1: Branch, 2: GeofenceResult, 3: array} */
    private function applyClockIn(array $c, array $reasons): array
    {
        /** @var User $employee */
        $employee = $c['employee'];
        /** @var PunchData $data */
        $data = $c['data'];
        /** @var Carbon $at */
        $at    = $c['at'];
        $today = $at->toDateString();

        $existing = Attendance::where('user_id', $employee->id)
            ->where('work_date', $today)
            ->lockForUpdate()
            ->first();

        if ($existing && ! is_null($existing->clock_in_at)) {
            throw new AttendanceException(
                'Ya registraste tu entrada hoy. '
                . ($existing->clock_out_at ? 'Tu jornada está completa.' : 'Tu jornada está en curso.'),
                'ALREADY_CLOCKED_IN',
                409,
            );
        }

        // Itinerantes (choferes, relevos, técnicos): marcan en la sede donde estén o en ruta
        $branch = $employee->roaming_attendance_enabled
            ? $this->roamingBranch($employee, $today, $data)
            : $this->getEmployeeBranchForToday($employee, $today);
        $this->enforceAccuracy($data, $c['settings'], $branch);
        $geo    = $this->geofence->check($data->latitude, $data->longitude, $branch, $data->accuracyMeters);
        if (! $employee->roaming_attendance_enabled) {
            $reasons = $this->applyGeofencePolicy($geo, $employee, $branch, $reasons);
        }

        [$assignment, $hasAssignments] = $this->getCurrentShift($employee, $today);
        $shift = $assignment?->shift;

        if ($hasAssignments && $this->isOutsideSchedule($assignment, $today, $at, $c['tenant']->timezone)) {
            $reasons[] = AttendancePunch::REASON_OUTSIDE_SCHEDULE;
        }

        $reasons = $this->faceReasons($data, $c['settings'], $reasons);

        $lateMinutes = $this->calculateLateMinutes($shift, $today, $c['tenant']->timezone, $at);

        $attributes = [
            'tenant_id'                => $employee->tenant_id,
            'user_id'                  => $employee->id,
            'branch_id'                => $branch->id,
            'shift_id'                 => $shift?->id,
            'work_date'                => $today,

            'clock_in_at'              => $at,
            'clock_in_latitude'        => $data->latitude,
            'clock_in_longitude'       => $data->longitude,
            'clock_in_distance_meters' => $geo->distanceMeters,
            'clock_in_photo'           => $c['photoPath'],
            'clock_in_device_id'       => $data->deviceId ?? '',
            'clock_in_within_geofence' => $geo->isWithinGeofence,
            'clock_in_meta'            => array_merge($data->meta(), [
                'geofence'    => $geo->toArray(),
                'recorded_at' => $at->toIso8601String(),
            ], $this->offlineMeta($data->isOffline()), $reasons ? ['suspicion_reasons' => array_values(array_unique($reasons))] : []),
            'clock_in_accuracy_meters'    => $data->accuracyMeters,
            'clock_in_device_time'        => $data->deviceTime,
            'clock_in_time_drift_seconds' => $c['drift'],
            'clock_in_mock_location'      => $data->isMocked,
            'clock_in_face_detected'      => $data->faceDetected,
            'is_suspicious'               => $reasons !== [] || (bool) $existing?->is_suspicious,

            'status'       => $lateMinutes > 0 ? 'late' : 'present',
            'late_minutes' => $lateMinutes,
        ];

        if ($existing) {
            // Registro sin entrada (p. ej. una falta marcada antes por el comando): se completa
            $existing->fill($attributes)->save();
            $attendance = $existing;
        } else {
            $attendance = Attendance::create($attributes);
        }

        return [$attendance, $branch, $geo, $reasons];
    }

    /** @return array{0: Attendance, 1: ?Branch, 2: ?GeofenceResult, 3: array} */
    private function applyBreakStart(array $c, array $reasons): array
    {
        /** @var PunchData $data */
        $data = $c['data'];
        $at   = $c['at'];

        $attendance = $this->getOpenAttendanceOrFail($c['employee'], $data->isOffline() ? $at->toDateString() : null);

        if (! is_null($attendance->break_start_at)) {
            throw new AttendanceException('Ya iniciaste tu descanso.', 'BREAK_ALREADY_STARTED', 409);
        }

        $shift = $attendance->shift;
        if ($shift && ! $shift->break_allowed) {
            throw new AttendanceException('Tu turno no permite descansos.', 'BREAK_NOT_ALLOWED', 422);
        }

        if ($data->isOffline() && $at->lt($this->localTime($attendance->clock_in_at))) {
            throw new AttendanceException('El descanso sin conexión no puede ser anterior a tu entrada.', 'OFFLINE_BEFORE_CLOCK_IN', 422);
        }

        $this->enforceAccuracy($data, $c['settings'], $attendance->branch);
        $geo = $this->breakGeofence($attendance, $data);

        $attendance->update([
            'break_start_at'        => $at,
            'break_start_latitude'  => $data->latitude,
            'break_start_longitude' => $data->longitude,
            'clock_in_meta'         => $this->withBreakMeta($attendance, 'start', $data, $at, $geo),
        ]);

        return [$attendance, $attendance->branch, $geo, $reasons];
    }

    /** @return array{0: Attendance, 1: ?Branch, 2: ?GeofenceResult, 3: array} */
    private function applyBreakEnd(array $c, array $reasons): array
    {
        /** @var PunchData $data */
        $data = $c['data'];
        $at   = $c['at'];

        $attendance = $this->getOpenAttendanceOrFail($c['employee'], $data->isOffline() ? $at->toDateString() : null);

        if (is_null($attendance->break_start_at)) {
            throw new AttendanceException('No has iniciado un descanso.', 'BREAK_NOT_STARTED', 409);
        }

        if (! is_null($attendance->break_end_at)) {
            throw new AttendanceException('Ya finalizaste tu descanso.', 'BREAK_ALREADY_ENDED', 409);
        }

        $breakStart = $this->localTime($attendance->break_start_at);
        if ($at->lt($breakStart)) {
            throw new AttendanceException('El fin del descanso no puede ser anterior a su inicio.', 'OFFLINE_OUT_OF_ORDER', 422);
        }

        $breakMinutes = (int) $breakStart->diffInMinutes($at);

        $shift = $attendance->shift;
        if ($shift && $shift->break_allowed && $breakMinutes > $shift->break_duration_minutes) {
            logger()->info('Break excedido', [
                'user_id'       => $attendance->user_id,
                'limit_minutes' => $shift->break_duration_minutes,
                'taken_minutes' => $breakMinutes,
            ]);
        }

        $this->enforceAccuracy($data, $c['settings'], $attendance->branch);
        $geo = $this->breakGeofence($attendance, $data);

        $attendance->update([
            'break_end_at'        => $at,
            'break_end_latitude'  => $data->latitude,
            'break_end_longitude' => $data->longitude,
            'break_minutes'       => $breakMinutes,
            'clock_in_meta'       => $this->withBreakMeta($attendance, 'end', $data, $at, $geo),
        ]);

        return [$attendance, $attendance->branch, $geo, $reasons];
    }

    /** @return array{0: Attendance, 1: Branch, 2: GeofenceResult, 3: array} */
    private function applyClockOut(array $c, array $reasons): array
    {
        /** @var PunchData $data */
        $data         = $c['data'];
        $clockOutTime = $c['at'];
        $employee     = $c['employee'];

        // Sin conexión: la jornada abierta del día en que se marcó (puede ser ayer)
        $attendance = $this->getOpenAttendanceOrFail($employee, $data->isOffline() ? $clockOutTime->toDateString() : null);

        if ($data->isOffline() && $clockOutTime->lt($this->localTime($attendance->clock_in_at))) {
            throw new AttendanceException('La salida sin conexión no puede ser anterior a tu entrada.', 'OFFLINE_BEFORE_CLOCK_IN', 422);
        }

        $branch  = $attendance->branch;
        $this->enforceAccuracy($data, $c['settings'], $branch);
        $geo     = $this->geofence->check($data->latitude, $data->longitude, $branch, $data->accuracyMeters);
        if (! $employee->roaming_attendance_enabled) {
            $reasons = $this->applyGeofencePolicy($geo, $employee, $branch, $reasons);
        }

        $shift = $attendance->shift;
        if ($shift && $this->isOutsideShiftWindow($shift, $attendance->work_date->toDateString(), $clockOutTime, $c['tenant']->timezone)) {
            $reasons[] = AttendancePunch::REASON_OUTSIDE_SCHEDULE;
        }

        $reasons = $this->faceReasons($data, $c['settings'], $reasons);

        // Horas trabajadas. Con "Refrigerio pagado" (lunch_paid efectivo) el descanso no se descuenta.
        $clockInTime  = $this->localTime($attendance->clock_in_at);
        $breakMinutes = $c['settings']->lunch_paid ? 0 : ($attendance->break_minutes ?? 0);
        $totalMinutes = $clockInTime->diffInMinutes($clockOutTime);
        $workedHours  = round(max(0, $totalMinutes - $breakMinutes) / 60, 2);

        $dailyHours    = $shift ? (float) $shift->daily_hours : 8.0;
        $overtimeHours = max(0, round($workedHours - $dailyHours, 2));

        $attendance->update([
            'clock_out_at'              => $clockOutTime,
            'clock_out_latitude'        => $data->latitude,
            'clock_out_longitude'       => $data->longitude,
            'clock_out_distance_meters' => $geo->distanceMeters,
            'clock_out_photo'           => $c['photoPath'],
            'clock_out_device_id'       => $data->deviceId ?? '',
            'clock_out_within_geofence' => $geo->isWithinGeofence,
            'clock_out_meta'            => array_merge($data->meta(), [
                'geofence'    => $geo->toArray(),
                'recorded_at' => $clockOutTime->toIso8601String(),
            ], $this->offlineMeta($data->isOffline()), $reasons ? ['suspicion_reasons' => array_values(array_unique($reasons))] : []),
            'clock_out_accuracy_meters'    => $data->accuracyMeters,
            'clock_out_device_time'        => $data->deviceTime,
            'clock_out_time_drift_seconds' => $c['drift'],
            'clock_out_mock_location'      => $data->isMocked,
            'clock_out_face_detected'      => $data->faceDetected,
            'worked_hours'   => $workedHours,
            'overtime_hours' => $overtimeHours,
            'status'         => $this->determineFinalStatus($attendance, $workedHours, $dailyHours),
        ]);

        return [$attendance, $branch, $geo, $reasons];
    }

    // =========================================================================
    // CONTROLES
    // =========================================================================

    /**
     * GPS simulado, precisión y rostro. Rechaza (AttendanceException) o agrega
     * motivos de sospecha según la política de la empresa.
     */
    private function enforceDeviceTrust(User $employee, PunchData $data, TenantSetting $settings, array &$reasons): void
    {
        // Root, emulador, app modificada...: se acepta, pero RRHH la ve como sospechosa
        if ($data->deviceFlags) {
            $reasons[] = AttendancePunch::REASON_UNSAFE_DEVICE;
        }

        if ($data->isMocked) {
            if ($settings->mockLocationPolicy() === 'reject') {
                Log::warning('Marcación con ubicación simulada rechazada', [
                    'user_id'   => $employee->id,
                    'tenant_id' => $employee->tenant_id,
                    'device_id' => $data->deviceId,
                ]);

                throw new AttendanceException(
                    'Se detectó una ubicación simulada. Desactiva las apps de GPS falso para marcar.',
                    'MOCK_LOCATION_DETECTED',
                );
            }

            $reasons[] = AttendancePunch::REASON_MOCK_LOCATION;
        }

        // La app hace la detección de rostro; si informa que no hubo rostro, se rechaza
        if ($settings->face_detection_required && $data->faceDetected === false) {
            throw new AttendanceException(
                'No se detectó un rostro en la selfie. Toma la foto de frente y con buena luz.',
                'FACE_NOT_DETECTED',
            );
        }
    }

    /**
     * Precisión GPS máxima de la sede de la marcación (o de la empresa si la
     * sede no define la suya): una lectura menos precisa se rechaza.
     */
    private function enforceAccuracy(PunchData $data, TenantSetting $settings, ?Branch $branch): void
    {
        $maxAccuracy = $branch ? $branch->maxGpsAccuracy($settings) : $settings->maxGpsAccuracy();
        if ($maxAccuracy > 0 && $data->accuracyMeters !== null && $data->accuracyMeters > $maxAccuracy) {
            $shown = rtrim(rtrim(number_format($data->accuracyMeters, 2, '.', ''), '0'), '.');

            throw new AttendanceException(
                "La precisión del GPS es baja ({$shown} m). Sal a un lugar despejado e intenta de nuevo.",
                'GPS_ACCURACY_TOO_LOW',
            );
        }
    }

    /**
     * Señales de ubicación inventada contra las marcaciones anteriores del
     * colaborador (no rechazan, agregan motivos de sospecha):
     *  - IMPOSSIBLE_TRAVEL: desde la última marcación habría que moverse a más
     *    de MAX_TRAVEL_KMH (y más de MIN_TRAVEL_METERS, para no saltar con el ruido del GPS).
     *  - REPEATED_COORDINATES: las mismas coordenadas (±1 cm) que una marcación
     *    de otro día en los últimos 30 días; un GPS real nunca repite así.
     */
    private function locationReasons(User $employee, PunchData $data, CarbonImmutable $serverTime): array
    {
        $reasons = [];
        $at      = $data->isOffline() ? $data->offlineRecordedAt : $serverTime;

        $previous = AttendancePunch::withoutGlobalScopes()
            ->where('tenant_id', $employee->tenant_id)
            ->where('user_id', $employee->id)
            ->orderByDesc('server_time')
            ->first(['latitude', 'longitude', 'server_time', 'offline_recorded_at']);

        if ($previous && $previous->latitude !== null) {
            $meters  = $this->geofence->calculateDistance($data->latitude, $data->longitude, $previous->latitude, $previous->longitude);
            $before  = $previous->offline_recorded_at ?? $previous->server_time;
            $seconds = max(60, abs($at->getTimestamp() - $before->getTimestamp()));

            if ($meters > self::MIN_TRAVEL_METERS && ($meters / 1000) / ($seconds / 3600) > self::MAX_TRAVEL_KMH) {
                $reasons[] = AttendancePunch::REASON_IMPOSSIBLE_TRAVEL;
            }
        }

        $epsilon  = 0.0000001;
        $repeated = AttendancePunch::withoutGlobalScopes()
            ->where('tenant_id', $employee->tenant_id)
            ->where('user_id', $employee->id)
            ->whereBetween('latitude', [$data->latitude - $epsilon, $data->latitude + $epsilon])
            ->whereBetween('longitude', [$data->longitude - $epsilon, $data->longitude + $epsilon])
            ->where('server_time', '>=', $serverTime->subDays(30))
            ->where('server_time', '<', $at->setTimezone(tenant_timezone())->startOfDay()->utc())
            ->exists();

        if ($repeated) {
            $reasons[] = AttendancePunch::REASON_REPEATED_COORDINATES;
        }

        return $reasons;
    }

    /** Rostro exigido pero la app no informó el resultado → FACE_UNVERIFIED (no se rechaza) */
    private function faceReasons(PunchData $data, TenantSetting $settings, array $reasons): array
    {
        $selfieInPlay = $data->photo !== null || $settings->selfie_required;

        if ($settings->face_detection_required && $selfieInPlay && $data->faceDetected === null) {
            $reasons[] = AttendancePunch::REASON_FACE_UNVERIFIED;
        }

        return $reasons;
    }

    /**
     * Hora efectiva de la marcación en la zona de la empresa: la del servidor,
     * o offline_recorded_at si la empresa permite offline y está dentro de
     * offline_max_hours.
     */
    private function resolveTime(PunchData $data, TenantSetting $settings, Tenant $tenant): Carbon
    {
        if (! $data->isOffline()) {
            return Carbon::now($tenant->timezone);
        }

        if (! $settings->offline_mode_enabled) {
            throw new AttendanceException('Tu empresa no permite registrar marcaciones sin conexión.', 'OFFLINE_NOT_ALLOWED');
        }

        $maxHours = max(1, (int) $settings->offline_max_hours);
        $at       = Carbon::instance($data->offlineRecordedAt->toDateTime())->setTimezone($tenant->timezone);

        if ($at->lt(Carbon::now($tenant->timezone)->subHours($maxHours))) {
            throw new AttendanceException(
                "La marcación sin conexión tiene más de {$maxHours} h. Pide a RRHH que la registre manualmente.",
                'OFFLINE_TOO_OLD',
            );
        }

        return $at;
    }

    /**
     * Desfase del reloj del teléfono (s, teléfono − servidor). En línea se usa
     * device_time; en una marcación offline device_time es de otro momento, así
     * que solo se mide con device_sent_at (reloj del teléfono al enviar).
     */
    private function timeDrift(PunchData $data, CarbonImmutable $serverTime): ?int
    {
        $reference = $data->isOffline() ? $data->deviceSentAt : ($data->deviceTime ?? $data->deviceSentAt);

        return $reference ? (int) round($reference->getTimestamp() - $serverTime->getTimestamp()) : null;
    }

    /**
     * Política de geocerca de la sede (o CompanyConfiguration::geofence_policy):
     * "reject" rechaza fuera del radio; "flag" acepta con motivo OUT_OF_GEOFENCE.
     * La zona de borde (GeofenceService::check) se acepta siempre con GEOFENCE_EDGE.
     */
    private function applyGeofencePolicy(GeofenceResult $result, User $employee, Branch $branch, array $reasons): array
    {
        if ($result->isWithinGeofence) {
            return $reasons;
        }

        // Zona de borde: la duda es de la precisión del GPS, se acepta observada
        if ($result->isEdge) {
            $reasons[] = AttendancePunch::REASON_GEOFENCE_EDGE;

            return $reasons;
        }

        if (! $branch->flagsOutOfGeofence()) {
            throw new AttendanceException($result->getMessage(), 'GEOFENCE_VIOLATION', 422, [
                'branch_id'       => $branch->id,
                'distance_meters' => $result->distanceMeters,
                'allowed_radius'  => $result->allowedRadius,
            ]);
        }

        $reasons[] = AttendancePunch::REASON_OUT_OF_GEOFENCE;

        return $reasons;
    }

    /**
     * ¿Fuera de la ventana del turno ± tolerancia? Con asignaciones pero ningún
     * turno ese día (día de descanso) también cuenta como fuera de horario.
     */
    private function isOutsideSchedule(?EmployeeShift $assignment, string $date, Carbon $at, string $timezone): bool
    {
        if (! $assignment?->shift || ! $assignment->worksOn(Carbon::parse($date))) {
            return true;
        }

        return $this->isOutsideShiftWindow($assignment->shift, $date, $at, $timezone);
    }

    private function isOutsideShiftWindow(Shift $shift, string $date, Carbon $at, string $timezone): bool
    {
        $grace = max(0, (int) $shift->grace_period_minutes);
        $start = Carbon::parse("{$date} {$shift->start_time}", $timezone);
        $end   = Carbon::parse("{$date} {$shift->end_time}", $timezone);

        if ($shift->crosses_midnight || $end->lte($start)) {
            $end->addDay();
        }

        $instant = $at->copy()->setTimezone($timezone);

        return $instant->lt($start->subMinutes($grace)) || $instant->gt($end->addMinutes($grace));
    }

    // =========================================================================
    // IDEMPOTENCIA
    // =========================================================================

    private function findDuplicate(User $employee, string $clientUuid): ?PunchResult
    {
        $punch = AttendancePunch::withoutGlobalScopes()
            ->where('tenant_id', $employee->tenant_id)
            ->where('client_uuid', $clientUuid)
            ->first();

        if (! $punch) {
            return null;
        }

        if ((int) $punch->user_id !== (int) $employee->id) {
            throw new AttendanceException('Este identificador de marcación ya fue usado por otra persona.', 'CLIENT_UUID_CONFLICT', 409);
        }

        $attendance = Attendance::withTrashed()->with(['branch', 'shift'])->findOrFail($punch->attendance_id);

        return new PunchResult($attendance, $punch, duplicate: true);
    }

    // =========================================================================
    // DESPUÉS DEL COMMIT
    // =========================================================================

    private function afterCommit(PunchResult $result): void
    {
        try {
            ReverseGeocodePunch::dispatch($result->punch->id)->afterCommit();
        } catch (\Throwable $e) {
            // Con la cola "sync" corre aquí: la dirección es informativa, nunca tumba la marcación
            Log::warning('No se pudo geocodificar la marcación: ' . $e->getMessage(), ['punch_id' => $result->punch->id]);
        }

        $reasons = $result->punch->suspicion_reasons ?? [];

        if (in_array(AttendancePunch::REASON_OUTSIDE_SCHEDULE, $reasons, true)) {
            $this->alerts->outsideSchedule($result->attendance, $result->punch);
        }

        if (in_array(AttendancePunch::REASON_OUT_OF_GEOFENCE, $reasons, true)) {
            $this->alerts->outsideGeofence($result->attendance->user ?? User::find($result->punch->user_id), [
                'branch_id'       => $result->punch->branch_id,
                'distance_meters' => $result->punch->distance_meters,
                'allowed_radius'  => $result->attendance->branch?->allowed_radius_meters,
                'attendance_id'   => $result->attendance->id,
                'punch_id'        => $result->punch->id,
            ], $result->punch->type, rejected: false);
        }
    }

    // =========================================================================
    // HELPERS PRIVADOS
    // =========================================================================

    /**
     * Las columnas datetime guardan la hora de pared de la empresa sin zona;
     * Eloquent las lee en la zona de la app. Esto las reinterpreta en la zona
     * de la empresa para comparar y restar con now(tenant_timezone()).
     */
    private function localTime(Carbon $stored): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i:s', $stored->format('Y-m-d H:i:s'), tenant_timezone());
    }

    /** Distancia a la sucursal en un descanso (informativa, no se exige geocerca) */
    private function breakGeofence(Attendance $attendance, PunchData $data): ?GeofenceResult
    {
        try {
            return $attendance->branch ? $this->geofence->check($data->latitude, $data->longitude, $attendance->branch) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Datos del descanso en clock_in_meta.breaks.{start|end} */
    private function withBreakMeta(Attendance $attendance, string $moment, PunchData $data, Carbon $at, ?GeofenceResult $geo): array
    {
        $info = array_merge($data->meta(), ['recorded_at' => $at->toIso8601String()], $this->offlineMeta($data->isOffline()));
        if ($geo) {
            $info['distance_meters'] = $geo->distanceMeters;
        }

        $clockInMeta = $attendance->clock_in_meta ?? [];
        $clockInMeta['breaks'][$moment] = $info;

        return $clockInMeta;
    }

    /** Marca de sincronización: offline + cuándo llegó al servidor */
    private function offlineMeta(bool $offline): array
    {
        return $offline ? ['offline' => true, 'synced_at' => now()->toIso8601String()] : [];
    }

    private function deletePhoto(?string $path): void
    {
        if ($path) {
            try {
                Storage::disk($this->selfies->disk())->delete($path);
            } catch (\Throwable) {
                // Un archivo huérfano no debe ocultar el error original
            }
        }
    }

    /** Jornada abierta del día (con bloqueo para evitar carreras). */
    private function getOpenAttendanceOrFail(User $employee, ?string $date = null): Attendance
    {
        $today = $date ?? Carbon::now(tenant_timezone())->toDateString();

        $attendance = Attendance::where('user_id', $employee->id)
            ->where('work_date', $today)
            ->whereNotNull('clock_in_at')
            ->whereNull('clock_out_at')
            ->lockForUpdate()
            ->first();

        if (! $attendance) {
            throw new AttendanceException(
                'No tienes una jornada abierta hoy. Primero debes marcar tu entrada.',
                'NO_OPEN_ATTENDANCE',
                404,
            );
        }

        return $attendance;
    }

    /**
     * Sede de la marcación de un itinerante: la sede activa de la empresa cuya
     * geocerca contiene el punto (la más cercana si hay varias). En ruta, fuera
     * de toda geocerca, queda en su sede base (o la más cercana si no tiene) y
     * la marcación guarda igual sus coordenadas y dirección.
     */
    private function roamingBranch(User $employee, string $date, PunchData $data): Branch
    {
        // ponytail: recorre las sedes de la empresa en PHP (decenas o cientos); índice espacial si fueran miles
        $nearest = Branch::where('is_active', true)->get()
            ->map(fn (Branch $b) => [$b, $this->geofence->check($data->latitude, $data->longitude, $b)])
            ->sortBy(fn (array $pair) => $pair[1]->distanceMeters)
            ->values();

        $inside = $nearest->first(fn (array $pair) => $pair[1]->isWithinGeofence);
        if ($inside) {
            return $inside[0];
        }

        try {
            return $this->getEmployeeBranchForToday($employee, $date);
        } catch (AttendanceException $e) {
            return $nearest->first()[0] ?? throw $e;
        }
    }

    /** Sucursal vigente del empleado para la fecha (principal primero). */
    private function getEmployeeBranchForToday(User $employee, string $date): Branch
    {
        $branch = $employee->branches()
            // Sin fecha de inicio (asignada desde el panel) = vigente desde siempre
            ->where(function ($q) use ($date) {
                $q->whereNull('employee_branch.assigned_from')
                    ->orWhere('employee_branch.assigned_from', '<=', $date);
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('employee_branch.assigned_until')
                    ->orWhere('employee_branch.assigned_until', '>=', $date);
            })
            ->orderByPivot('is_primary', 'desc')
            ->where('branches.is_active', true)
            ->first();

        if (! $branch) {
            throw new AttendanceException(
                'No tienes ninguna sucursal asignada. Contacta a RRHH.',
                'NO_BRANCH_ASSIGNED',
                422,
            );
        }

        return $branch;
    }

    /**
     * Asignación de turno para la fecha: la que realmente toca ese día (fija o
     * rotativa) o, si ninguna aplica, la primera vigente.
     *
     * @return array{0: ?EmployeeShift, 1: bool}  [asignación, ¿tiene asignaciones vigentes?]
     */
    private function getCurrentShift(User $employee, string $date): array
    {
        $assignments = EmployeeShift::where('user_id', $employee->id)
            ->current($date)
            ->with('shift')
            ->get();

        $day = Carbon::parse($date);

        return [
            $assignments->first(fn (EmployeeShift $a) => $a->worksOn($day)) ?? $assignments->first(),
            $assignments->isNotEmpty(),
        ];
    }

    /** Minutos de tardanza contra la hora de entrada del turno (más la tolerancia). */
    private function calculateLateMinutes(?Shift $shift, string $date, string $timezone, ?Carbon $at = null): int
    {
        if (! $shift) {
            return 0;
        }

        $now = $at ?? Carbon::now($timezone);

        $expectedEntry = Carbon::parse("{$date} {$shift->start_time}", $timezone)
            ->addMinutes($shift->grace_period_minutes);

        if ($now->lessThanOrEqualTo($expectedEntry)) {
            return 0;
        }

        $scheduledEntry = Carbon::parse("{$date} {$shift->start_time}", $timezone);

        return (int) $scheduledEntry->diffInMinutes($now);
    }

    private function determineFinalStatus(Attendance $attendance, float $workedHours, float $dailyHours): string
    {
        if ($attendance->status === 'late') {
            return 'late';
        }

        if ($workedHours < ($dailyHours * 0.60)) {
            return 'half_day';
        }

        return 'present';
    }

    /** Orden de procesamiento de sync-batch para marcaciones con la misma hora */
    public static function typeOrder(string $type): int
    {
        return self::TYPE_ORDER[$type] ?? 9;
    }
}
