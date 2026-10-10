<?php

namespace App\Modules\Attendance\Controllers\Api\Admin;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\AttendanceEvidence;
use Illuminate\Http\JsonResponse;

/**
 * Detalle de una marcación para el visor de auditoría: selfie (URL temporal),
 * hora exacta, sucursal, punto GPS y comparación contra el radio de la geocerca.
 */
class AttendanceDetailController extends Controller
{
    /** GET /api/admin/attendance/{id} */
    public function show(int $id, AttendanceEvidence $evidence): JsonResponse
    {
        $attendance = Attendance::with(['user', 'branch', 'shift'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $evidence->detail($attendance)]);
    }
}
