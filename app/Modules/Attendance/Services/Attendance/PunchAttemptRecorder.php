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
    public function record(User $employee, PunchData $data, AttendanceException $e): ?AttendancePunchAttempt
    {
        try {
            $context  = $e->context;
            $branchId = $context['branch_id'] ?? $employee->primaryBranch()->value('branches.id');

            return AttendancePunchAttempt::withoutGlobalScopes()->create([
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
            ]);
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
            return AttendancePunchAttempt::withoutGlobalScopes()->create([
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
            ]);
        } catch (\Throwable $err) {
            report($err);

            return null;
        }
    }
}
