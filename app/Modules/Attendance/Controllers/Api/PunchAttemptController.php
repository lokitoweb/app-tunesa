<?php

namespace App\Modules\Attendance\Controllers\Api;

use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Models\AttendancePunchAttempt;
use App\Modules\Attendance\Services\Attendance\PunchAttemptRecorder;
use App\Modules\Core\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /api/attendance/attempts — la app reporta una marcación que bloqueó
 * antes de enviarla (GPS falso, GPS impreciso o fuera del radio en su
 * verificación previa). Sin esto esos intentos nunca llegaban al servidor.
 * Idempotente por client_uuid: 201 nuevo, 200 si ya estaba.
 */
class PunchAttemptController extends Controller
{
    public function __construct(private readonly PunchAttemptRecorder $recorder) {}

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'client_uuid'     => ['required', 'uuid'],
            'type'            => ['required', Rule::in(AttendancePunch::TYPES)],
            'code'            => ['required', Rule::in(AttendancePunchAttempt::APP_CODES)],
            'occurred_at'     => ['required', 'date', 'after:-7 days', 'before:+10 minutes'],
            'latitude'        => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude'       => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'distance_meters' => ['nullable', 'numeric', 'min:0', 'max:20000000'],
            'allowed_radius'  => ['nullable', 'integer', 'min:0', 'max:100000'],
            'branch_id'       => ['nullable', 'integer'],
            'is_mocked'       => ['nullable', 'boolean'],
            'offline'         => ['nullable', 'boolean'],
            'device_id'       => ['nullable', 'string', 'max:191'],
            'device_os'       => ['nullable', 'string', 'max:100'],
            'app_version'     => ['nullable', 'string', 'max:50'],
        ]);

        $input['occurred_at'] = \Carbon\CarbonImmutable::parse($input['occurred_at'])->utc();

        [$attempt, $created] = $this->recorder->recordFromApp($request->user(), $input, $request->ip());

        return response()->json([
            'success'   => true,
            'id'        => $attempt->id,
            'duplicate' => ! $created,
        ], $created ? 201 : 200);
    }
}
