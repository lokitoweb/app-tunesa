<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Services\AttendanceEvidence;
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
