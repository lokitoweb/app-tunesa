<?php

namespace App\Modules\Attendance\Services\Attendance;

use App\Modules\Attendance\Exceptions\AttendanceException;
use App\Modules\Attendance\Models\AttendancePunchAttempt;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Core\Models\User;
use Illuminate\Http\Request;

/**
 * Registra cada marcación rechazada por una regla de negocio (AttendanceException).
 * Nunca debe tumbar la respuesta al colaborador: si falla, solo se reporta.
 */
class PunchAttemptRecorder
{
    public function __construct(private readonly AttendanceAlertNotifier $alerts) {}

    public function record(User $employee, PunchData $data, AttendanceException $e): ?AttendancePunchAttempt
    {
        try {
            $context  = $e->context;
            $branchId = $context['branch_id'] ?? $employee->primaryBranch()->value('branches.id');

            return $this->saved(AttendancePunchAttempt::withoutGlobalScopes()->create([
                'tenant_id'              => $employee->tenant_id,
                'user_id'                => $employee->id,
                'branch_id'              => $branchId,
                'type'                   => $data->type,
                'error_code'             => $e->errorCode,
                'message'                => mb_substr($e->getMessage(), 0, 500),
                'latitude'               => $data->latitude,
                'longitude'              => $data->longitude,
                'accuracy_meters'        => $data->accuracyMeters,
                'distance_meters'        => isset($context['distance_meters']) ? (int) round($context['distance_meters']) : null,
                'allowed_radius'         => isset($context['allowed_radius']) ? (int) $context['allowed_radius'] : null,
                'mock_location_detected' => $data->isMocked,
                'device_flags'           => $data->deviceFlags ?: null,
                'offline'                => $data->offlineRecordedAt !== null,
                'offline_recorded_at'    => $data->offlineRecordedAt,
                'device_time'            => $data->deviceTime,
                'client_uuid'            => $data->clientUuid,
                'device_id'              => $data->deviceId ? mb_substr($data->deviceId, 0, 191) : null,
                'device_os'              => $data->deviceOs ? mb_substr($data->deviceOs, 0, 100) : null,
                'app_version'            => $data->appVersion ? mb_substr($data->appVersion, 0, 50) : null,
                'ip'                     => $data->ip,
            ]), $employee);
        } catch (\Throwable $err) {
            report($err);

            return null;
        }
    }

    /**
     * Rechazo antes de llegar al servicio (candado de incorporación): solo hay
     * la petición cruda, así que se guarda lo que traiga (GPS, teléfono).
     */
    public function recordBlocked(User $employee, Request $request, string $type, string $code, string $message): ?AttendancePunchAttempt
    {
        $input = $request->input('punches.0', $request->all());
        $input = is_array($input) ? $input : [];
        $num   = fn (string $key) => is_numeric($input[$key] ?? null) ? (float) $input[$key] : null;
        $str   = fn (string $key, int $max) => is_string($input[$key] ?? null) && $input[$key] !== '' ? mb_substr($input[$key], 0, $max) : null;

        try {
            return $this->saved(AttendancePunchAttempt::withoutGlobalScopes()->create([
                'tenant_id'   => $employee->tenant_id,
                'user_id'     => $employee->id,
                'branch_id'   => $employee->primaryBranch()->value('branches.id'),
                'type'        => in_array($type, AttendancePunch::TYPES, true) ? $type : 'clock_in',
                'error_code'  => $code,
                'message'     => mb_substr($message, 0, 500),
                'latitude'    => ($lat = $num('latitude')) !== null && abs($lat) <= 90 ? $lat : null,
                'longitude'   => ($lng = $num('longitude')) !== null && abs($lng) <= 180 ? $lng : null,
                'accuracy_meters' => ($acc = $num('accuracy_meters')) !== null && $acc >= 0 && $acc < 1000000 ? $acc : null,
                'offline'     => filled($input['offline_recorded_at'] ?? null),
                'device_id'   => $str('device_id', 191),
                'device_os'   => $str('device_os', 100),
                'app_version' => $str('app_version', 50),
                'ip'          => $request->ip(),
            ]), $employee);
        } catch (\Throwable $err) {
            report($err);

            return null;
        }
    }

    /**
     * Bloqueo que hizo la app antes de enviar (POST /api/attendance/attempts).
     * Idempotente por client_uuid: la app reintenta los que no pudo enviar.
     *
     * @param  array<string, mixed>  $input  Campos ya validados
     * @return array{0: AttendancePunchAttempt, 1: bool}  [intento, ¿nuevo?]
     */
    public function recordFromApp(User $employee, array $input, ?string $ip): array
    {
        $existing = AttendancePunchAttempt::withoutGlobalScopes()
            ->where('tenant_id', $employee->tenant_id)
            ->where('user_id', $employee->id)
            ->where('client_uuid', $input['client_uuid'])
            ->first();

        if ($existing) {
            return [$existing, false];
        }

        $branchId = $input['branch_id'] ?? null;
        if ($branchId && ! $employee->branches()->whereKey($branchId)->exists()) {
            $branchId = null;
        }

        $attempt = AttendancePunchAttempt::withoutGlobalScopes()->create([
            'tenant_id'              => $employee->tenant_id,
            'user_id'                => $employee->id,
            'branch_id'              => $branchId ?? $employee->primaryBranch()->value('branches.id'),
            'type'                   => $input['type'],
            'error_code'             => $input['code'],
            'source'                 => AttendancePunchAttempt::SOURCE_APP,
            'message'                => mb_substr(self::APP_MESSAGES[$input['code']], 0, 500),
            'latitude'               => $input['latitude'] ?? null,
            'longitude'              => $input['longitude'] ?? null,
            'accuracy_meters'        => $input['accuracy_meters'] ?? null,
            'distance_meters'        => isset($input['distance_meters']) ? (int) round($input['distance_meters']) : null,
            'allowed_radius'         => isset($input['allowed_radius']) ? (int) $input['allowed_radius'] : null,
            'mock_location_detected' => (bool) ($input['is_mocked'] ?? false),
            'offline'                => (bool) ($input['offline'] ?? false),
            'device_time'            => $input['occurred_at'],
            'client_uuid'            => $input['client_uuid'],
            'device_id'              => isset($input['device_id']) ? mb_substr($input['device_id'], 0, 191) : null,
            'device_os'              => isset($input['device_os']) ? mb_substr($input['device_os'], 0, 100) : null,
            'app_version'            => isset($input['app_version']) ? mb_substr($input['app_version'], 0, 50) : null,
            'ip'                     => $ip,
        ]);

        return [$this->saved($attempt, $employee), true];
    }

    /** Lo que vio el colaborador en la app para cada bloqueo */
    public const APP_MESSAGES = [
        'MOCK_LOCATION_DETECTED' => 'La app detectó una ubicación simulada y no envió la marcación.',
        'GPS_ACCURACY_TOO_LOW'   => 'La app no envió la marcación: la señal GPS era imprecisa.',
        'GEOFENCE_VIOLATION'     => 'La app no envió la marcación: estaba fuera del radio de la sucursal.',
    ];

    /** Tras guardar: con GPS falso repetido en el día, avisa a supervisor y RRHH */
    private function saved(AttendancePunchAttempt $attempt, User $employee): AttendancePunchAttempt
    {
        if ($attempt->error_code === 'MOCK_LOCATION_DETECTED') {
            $start = now(tenant_timezone())->startOfDay()->setTimezone(config('app.timezone'));
            $count = AttendancePunchAttempt::withoutGlobalScopes()
                ->where('tenant_id', $employee->tenant_id)
                ->where('user_id', $employee->id)
                ->where('error_code', 'MOCK_LOCATION_DETECTED')
                ->where('created_at', '>=', $start)
                ->count();

            if ($count >= AttendanceAlertNotifier::MOCK_ATTEMPTS_ALERT) {
                $this->alerts->mockRepeated($employee, $count, $attempt->branch_id);
            }
        }

        return $attempt;
    }
}
