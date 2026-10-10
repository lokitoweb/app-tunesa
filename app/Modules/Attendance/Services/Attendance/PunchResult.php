<?php

namespace App\Modules\Attendance\Services\Attendance;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;

/** Resultado de AttendanceService::punch(): la jornada, el evento y si ya existía (idempotencia) */
final class PunchResult
{
    public function __construct(
        public readonly Attendance $attendance,
        public readonly AttendancePunch $punch,
        public readonly bool $duplicate = false,
    ) {}
}
