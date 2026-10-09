<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BreakRequest;
use App\Http\Requests\Api\ClockInRequest;
use App\Http\Requests\Api\ClockOutRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\User;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AttendanceController
 *
 * Expone los 4 endpoints del ciclo de marcación y los reportes de asistencia.
 * Delega TODA la lógica al AttendanceService.
 * Este controlador solo: valida → delega → responde.
 *
 * Endpoints del ciclo de marcación:
 *   POST /api/attendance/clock-in      → Entrada (GPS + foto obligatoria)
 *   POST /api/attendance/break-start   → Inicio de descanso
 *   POST /api/attendance/break-end     → Fin de descanso
 *   POST /api/attendance/clock-out     → Salida (GPS + foto obligatoria)
 */
class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendanceService,
    ) {}

    // =========================================================================
    // 1. CLOCK-IN — ENTRADA
    // =========================================================================

    /**
     * Registra la entrada del empleado.
     *
     * Request (multipart/form-data):
     *   - latitude:        float  (GPS del dispositivo)
     *   - longitude:       float  (GPS del dispositivo)
     *   - photo:           file   (selfie JPG/PNG/WEBP, max 5MB)
     *   - device_id:       string (ID único del dispositivo)
     *   - accuracy_meters: float  (precisión del GPS)
     *   - device_os:       string (android|ios)
     *
     * Response 201: Registro de asistencia creado
     * Response 409: Ya marcó entrada hoy
     * Response 422: Fuera de geocerca / coordenadas inválidas
     */
    public function clockIn(ClockInRequest $request): JsonResponse
    {
        try {
            $attendance = $this->attendanceService->clockIn(
                employee:  $request->user(),
                latitude:  (float) $request->input('latitude'),
                longitude: (float) $request->input('longitude'),
                photo:     $request->file('photo'),
                deviceId:  $request->input('device_id', ''),
                meta:      $request->deviceMeta(),
            );

            return response()->json([
                'success' => true,
                'message' => '✅ Entrada registrada correctamente.',
                'data'    => new AttendanceResource($attendance),
            ], 201);

        } catch (\Exception $e) {
            return $this->handleAttendanceException($e);
        }
    }

    // =========================================================================
    // 2. BREAK START — INICIO DE DESCANSO
    // =========================================================================

    /**
     * Registra el inicio del descanso/lunch.
     *
     * Request (JSON o form):
     *   - latitude:  float
     *   - longitude: float
     */
    public function breakStart(BreakRequest $request): JsonResponse
    {
        try {
            $attendance = $this->attendanceService->breakStart(
                employee:  $request->user(),
                latitude:  (float) $request->input('latitude'),
                longitude: (float) $request->input('longitude'),
            );

            return response()->json([
                'success' => true,
                'message' => '☕ Descanso iniciado.',
                'data'    => new AttendanceResource($attendance),
            ]);

        } catch (\Exception $e) {
            return $this->handleAttendanceException($e);
        }
    }

    // =========================================================================
    // 3. BREAK END — FIN DE DESCANSO
    // =========================================================================

    /**
     * Registra el regreso del descanso.
     */
    public function breakEnd(BreakRequest $request): JsonResponse
    {
        try {
            $attendance = $this->attendanceService->breakEnd(
                employee:  $request->user(),
                latitude:  (float) $request->input('latitude'),
                longitude: (float) $request->input('longitude'),
            );

            return response()->json([
                'success' => true,
                'message' => '🔄 Regreso de descanso registrado.',
                'data'    => new AttendanceResource($attendance),
            ]);

        } catch (\Exception $e) {
            return $this->handleAttendanceException($e);
        }
    }

    // =========================================================================
    // 4. CLOCK-OUT — SALIDA
    // =========================================================================

    /**
     * Registra la salida del empleado y calcula horas trabajadas.
     *
     * Request (multipart/form-data):
     *   - latitude, longitude, photo, device_id (igual que clock-in)
     */
    public function clockOut(ClockOutRequest $request): JsonResponse
    {
        try {
            $attendance = $this->attendanceService->clockOut(
                employee:  $request->user(),
                latitude:  (float) $request->input('latitude'),
                longitude: (float) $request->input('longitude'),
                photo:     $request->file('photo'),
                deviceId:  $request->input('device_id', ''),
                meta:      $request->deviceMeta(),
            );

            $workedHours = number_format($attendance->worked_hours, 1);

            return response()->json([
                'success' => true,
                'message' => "🏁 Salida registrada. Horas trabajadas hoy: {$workedHours}h.",
                'data'    => new AttendanceResource($attendance),
            ]);

        } catch (\Exception $e) {
            return $this->handleAttendanceException($e);
        }
    }

    // =========================================================================
    // CONSULTAS DE ASISTENCIA
    // =========================================================================

    /**
     * Devuelve el estado actual de la asistencia del empleado en el día de hoy.
     * La app móvil llama esto al abrirse para saber qué botón mostrar.
     *
     * GET /api/attendance/today
     */
    public function today(Request $request): JsonResponse
    {
        $employee = $request->user();
        $today    = Carbon::now(tenant_timezone())->toDateString();

        $attendance = Attendance::where('user_id', $employee->id)
            ->where('work_date', $today)
            ->with(['branch', 'shift'])
            ->first();

        // Determinar el próximo paso disponible para la UI de la app
        $nextAction = $this->determineNextAction($attendance);

        return response()->json([
            'success'     => true,
            'data'        => $attendance ? new AttendanceResource($attendance) : null,
            'next_action' => $nextAction,
            'server_time' => Carbon::now(tenant_timezone())->toIso8601String(),
            'timezone'    => tenant_timezone(),
        ]);
    }

    /**
     * Historial de asistencias del empleado (con paginación).
     *
     * GET /api/attendance/history?month=10&year=2024&page=1
     */
    public function history(Request $request): JsonResponse
    {
        $employee = $request->user();

        $request->validate([
            'month' => ['sometimes', 'integer', 'between:1,12'],
            'year'  => ['sometimes', 'integer', 'min:2020'],
        ]);

        $month = $request->input('month', now()->month);
        $year  = $request->input('year',  now()->year);

        $attendances = Attendance::where('user_id', $employee->id)
            ->forPeriod($year, $month)
            ->with(['branch', 'shift'])
            ->orderBy('work_date', 'desc')
            ->paginate(31); // Max días del mes

        // Resumen del período
        $summary = [
            'total_days'     => $attendances->total(),
            'present'        => $attendances->where('status', 'present')->count(),
            'late'           => $attendances->where('status', 'late')->count(),
            'absent'         => $attendances->where('status', 'absent')->count(),
            'total_hours'    => round($attendances->sum('worked_hours'), 1),
            'overtime_hours' => round($attendances->sum('overtime_hours'), 1),
            'late_minutes'   => $attendances->sum('late_minutes'),
        ];

        return response()->json([
            'success'  => true,
            'period'   => ['month' => $month, 'year' => $year],
            'summary'  => $summary,
            'data'     => AttendanceResource::collection($attendances),
            'meta'     => [
                'current_page' => $attendances->currentPage(),
                'total'        => $attendances->total(),
            ],
        ]);
    }

    // =========================================================================
    // DASHBOARD EN TIEMPO REAL (Admin / HR)
    // =========================================================================

    /**
     * Retorna el estado en tiempo real de todos los empleados para hoy.
     * Usado por el dashboard web del panel de administración.
     *
     * GET /api/admin/dashboard/live?branch_id=1
     */
    public function liveDashboard(Request $request): JsonResponse
    {
        $tenantId = current_tenant_id();
        $today    = Carbon::now(tenant_timezone())->toDateString();
        $branchId = $request->input('branch_id');

        $query = Attendance::where('tenant_id', $tenantId)
            ->where('work_date', $today)
            ->with(['user', 'branch', 'shift'])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId));

        $attendances = $query->get();

        // Contar empleados activos del tenant (para saber cuántos están ausentes)
        $totalEmployees = \App\Models\User::where('tenant_id', $tenantId)
            ->where('role', 'employee')
            ->where('status', 'active')
            ->when($branchId, fn($q) => $q->whereHas('branches', fn($bq) => $bq->where('branch_id', $branchId)))
            ->count();

        return response()->json([
            'success'      => true,
            'server_time'  => Carbon::now(tenant_timezone())->toIso8601String(),
            'summary'      => [
                'total_employees' => $totalEmployees,
                'present'         => $attendances->whereNotNull('clock_in_at')->count(),
                'on_time'         => $attendances->where('status', 'present')->count(),
                'late'            => $attendances->where('status', 'late')->count(),
                'on_break'        => $attendances->filter(fn($a) => $a->isOnBreak())->count(),
                'clocked_out'     => $attendances->whereNotNull('clock_out_at')->count(),
                'absent'          => $totalEmployees - $attendances->whereNotNull('clock_in_at')->count(),
            ],
            'attendances'  => AttendanceResource::collection($attendances),
        ]);
    }

    /**
     * Reporte de asistencias filtrable para exportar.
     *
     * GET /api/admin/reports/attendance?from=2024-10-01&to=2024-10-31&branch_id=1&status=late
     */
    public function report(Request $request): JsonResponse
    {
        $request->validate([
            'from'      => ['required', 'date'],
            'to'        => ['required', 'date', 'after_or_equal:from'],
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'user_id'   => ['sometimes', 'integer', 'exists:users,id'],
            'status'    => ['sometimes', 'string', 'in:present,late,absent,excused,half_day,incomplete'],
        ]);

        $attendances = Attendance::where('tenant_id', current_tenant_id())
            ->whereBetween('work_date', [$request->from, $request->to])
            ->when($request->branch_id, fn($q) => $q->where('branch_id', $request->branch_id))
            ->when($request->user_id,   fn($q) => $q->where('user_id',   $request->user_id))
            ->when($request->status,    fn($q) => $q->where('status',    $request->status))
            ->with(['user', 'branch', 'shift'])
            ->orderBy('work_date', 'desc')
            ->orderBy('user_id')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data'    => AttendanceResource::collection($attendances),
            'meta'    => [
                'from'         => $request->from,
                'to'           => $request->to,
                'total'        => $attendances->total(),
                'current_page' => $attendances->currentPage(),
                'last_page'    => $attendances->lastPage(),
            ],
        ]);
    }

    /**
     * Métricas agregadas para las tarjetas y gráficos del dashboard.
     *
     * GET /api/admin/dashboard/stats?from=2026-10-01&to=2026-10-31&branch_id=1
     */
    public function dashboardStats(Request $request): JsonResponse
    {
        $request->validate([
            'from'      => ['sometimes', 'date'],
            'to'        => ['sometimes', 'date', 'after_or_equal:from'],
            'branch_id' => ['sometimes', 'integer'],
        ]);

        $tz   = tenant_timezone();
        $to   = Carbon::parse($request->input('to', now($tz)->toDateString()));
        $from = Carbon::parse($request->input('from', $to->copy()->subDays(6)->toDateString()));

        if ($from->diffInDays($to) > 92) {
            return response()->json([
                'success' => false,
                'message' => 'El rango máximo es de 92 días.',
                'code'    => 'RANGE_TOO_LARGE',
            ], 422);
        }

        $base = Attendance::whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->when($request->branch_id, fn ($q) => $q->where('branch_id', $request->branch_id));

        $byStatus = (clone $base)->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $totals = (clone $base)->selectRaw(
            'COUNT(*) as records, COALESCE(SUM(worked_hours),0) as worked_hours, '
            . 'COALESCE(SUM(overtime_hours),0) as overtime_hours, COALESCE(SUM(late_minutes),0) as late_minutes, '
            . 'SUM(CASE WHEN clock_in_at IS NOT NULL AND clock_in_within_geofence = false THEN 1 ELSE 0 END) as out_of_geofence'
        )->first();

        // Serie diaria para el gráfico de tendencia
        $daily = (clone $base)->selectRaw(
            'work_date, COUNT(*) as total, '
            . "SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late, "
            . "SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent"
        )->groupBy('work_date')->get()->keyBy(fn ($r) => Carbon::parse($r->work_date)->toDateString());

        $series = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $row = $daily->get($day->toDateString());
            $series[] = [
                'date'    => $day->toDateString(),
                'present' => (int) ($row->total ?? 0) - (int) ($row->absent ?? 0),
                'late'    => (int) ($row->late ?? 0),
                'absent'  => (int) ($row->absent ?? 0),
            ];
        }

        // Top tardanzas del período
        $topLate = (clone $base)->where('late_minutes', '>', 0)
            ->selectRaw('user_id, COUNT(*) as late_days, SUM(late_minutes) as late_minutes')
            ->groupBy('user_id')
            ->orderByDesc('late_minutes')
            ->limit(5)
            ->with('user:id,first_name,last_name,employee_code')
            ->get()
            ->map(fn ($r) => [
                'user_id'      => $r->user_id,
                'name'         => $r->user?->full_name,
                'late_days'    => (int) $r->late_days,
                'late_minutes' => (int) $r->late_minutes,
            ]);

        $activeEmployees = User::where('role', 'employee')->where('status', 'active')
            ->when($request->branch_id, fn ($q) => $q->whereHas('branches', fn ($bq) => $bq->where('branches.id', $request->branch_id)))
            ->count();

        return response()->json([
            'success' => true,
            'period'  => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'data'    => [
                'active_employees' => $activeEmployees,
                'active_branches'  => Branch::where('is_active', true)->count(),
                'pending_justifications' => Attendance::where('justification_status', 'pending')->count(),
                'records'          => (int) $totals->records,
                'by_status'        => $byStatus,
                'worked_hours'     => round((float) $totals->worked_hours, 1),
                'overtime_hours'   => round((float) $totals->overtime_hours, 1),
                'late_minutes'     => (int) $totals->late_minutes,
                'out_of_geofence'  => (int) $totals->out_of_geofence,
                'daily'            => $series,
                'top_late'         => $topLate,
            ],
        ]);
    }

    /**
     * Exporta el reporte de asistencias en CSV (abre en Excel).
     *
     * GET /api/admin/reports/attendance/export?from=...&to=...&branch_id=&user_id=&status=
     */
    public function exportReport(Request $request): StreamedResponse
    {
        $request->validate([
            'from'      => ['required', 'date'],
            'to'        => ['required', 'date', 'after_or_equal:from'],
            'branch_id' => ['sometimes', 'integer'],
            'user_id'   => ['sometimes', 'integer'],
            'status'    => ['sometimes', 'string', 'in:present,late,absent,excused,half_day,incomplete'],
        ]);

        // tenant_id explícito: el CSV se genera al enviar la respuesta, fuera
        // del ciclo normal de la request.
        $query = Attendance::where('tenant_id', current_tenant_id())
            ->whereBetween('work_date', [$request->from, $request->to])
            ->when($request->branch_id, fn ($q) => $q->where('branch_id', $request->branch_id))
            ->when($request->user_id,   fn ($q) => $q->where('user_id',   $request->user_id))
            ->when($request->status,    fn ($q) => $q->where('status',    $request->status))
            ->with(['user:id,first_name,last_name,employee_code,document_number', 'branch:id,name', 'shift:id,name'])
            ->orderBy('work_date')
            ->orderBy('user_id');

        $filename = "asistencias_{$request->from}_{$request->to}.csv";

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM para que Excel lea UTF-8 (tildes, ñ)

            fputcsv($out, [
                'Fecha', 'Código', 'Documento', 'Colaborador', 'Sucursal', 'Turno',
                'Entrada', 'Salida', 'Estado', 'Tardanza (min)', 'Descanso (min)',
                'Horas trabajadas', 'Horas extra', 'Dentro de geocerca', 'Distancia entrada (m)',
            ]);

            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $a) {
                    fputcsv($out, [
                        $a->work_date?->toDateString(),
                        $a->user?->employee_code,
                        $a->user?->document_number,
                        $a->user?->full_name,
                        $a->branch?->name,
                        $a->shift?->name,
                        $a->clock_in_at?->format('H:i'),
                        $a->clock_out_at?->format('H:i'),
                        $a->status,
                        $a->late_minutes,
                        $a->break_minutes,
                        $a->worked_hours,
                        $a->overtime_hours,
                        $a->clock_in_within_geofence ? 'Sí' : 'No',
                        $a->clock_in_distance_meters,
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // =========================================================================
    // SUPERVISOR (solo su equipo)
    // =========================================================================

    /**
     * Estado en tiempo real del equipo del supervisor.
     *
     * GET /api/supervisor/attendance/live
     */
    public function teamLive(Request $request): JsonResponse
    {
        $teamIds = $this->teamUserIds($request->user());
        $today   = Carbon::now(tenant_timezone())->toDateString();

        $attendances = Attendance::where('work_date', $today)
            ->whereIn('user_id', $teamIds)
            ->with(['user', 'branch', 'shift'])
            ->get();

        $present = $attendances->whereNotNull('clock_in_at')->count();

        return response()->json([
            'success'     => true,
            'server_time' => Carbon::now(tenant_timezone())->toIso8601String(),
            'summary'     => [
                'team_size'   => count($teamIds),
                'present'     => $present,
                'late'        => $attendances->where('status', 'late')->count(),
                'on_break'    => $attendances->filter(fn ($a) => $a->isOnBreak())->count(),
                'clocked_out' => $attendances->whereNotNull('clock_out_at')->count(),
                'absent'      => max(count($teamIds) - $present, 0),
            ],
            'attendances' => AttendanceResource::collection($attendances),
        ]);
    }

    /**
     * Reporte de asistencias del equipo del supervisor.
     *
     * GET /api/supervisor/attendance?from=...&to=...&user_id=&status=
     */
    public function teamReport(Request $request): JsonResponse
    {
        $request->validate([
            'from'    => ['sometimes', 'date'],
            'to'      => ['sometimes', 'date', 'after_or_equal:from'],
            'user_id' => ['sometimes', 'integer'],
            'status'  => ['sometimes', 'string', 'in:present,late,absent,excused,half_day,incomplete'],
        ]);

        $tz   = tenant_timezone();
        $from = $request->input('from', now($tz)->startOfMonth()->toDateString());
        $to   = $request->input('to', now($tz)->toDateString());

        $attendances = Attendance::whereIn('user_id', $this->teamUserIds($request->user()))
            ->whereBetween('work_date', [$from, $to])
            ->when($request->user_id, fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->status,  fn ($q) => $q->where('status',  $request->status))
            ->with(['user', 'branch', 'shift'])
            ->orderBy('work_date', 'desc')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data'    => AttendanceResource::collection($attendances),
            'meta'    => [
                'from'         => $from,
                'to'           => $to,
                'total'        => $attendances->total(),
                'current_page' => $attendances->currentPage(),
                'last_page'    => $attendances->lastPage(),
            ],
        ]);
    }

    // =========================================================================
    // JUSTIFICACIÓN (empleado)
    // =========================================================================

    /**
     * El empleado adjunta una justificación (tardanza, falta, marcación fuera
     * de geocerca). Queda pendiente de revisión por RRHH/supervisor.
     *
     * POST /api/attendance/{id}/justification  (multipart)
     */
    public function submitJustification(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['required', 'string', 'max:1000'],
            'file'  => ['sometimes', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $attendance = Attendance::where('user_id', $request->user()->id)->findOrFail($id);

        if ($attendance->justification_status === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Esta justificación ya fue aprobada.',
                'code'    => 'CONFLICT',
            ], 409);
        }

        $data = [
            'notes'                => $validated['notes'],
            'justification_status' => 'pending',
            'reviewed_by'          => null,
            'reviewed_at'          => null,
        ];

        if ($request->hasFile('file')) {
            $data['justification_file'] = $request->file('file')
                ->store("justifications/{$attendance->tenant_id}/{$attendance->user_id}", 'private');
        }

        $attendance->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Justificación enviada. Te avisaremos cuando sea revisada.',
            'data'    => new AttendanceResource($attendance->fresh(['branch', 'shift'])),
        ]);
    }

    /**
     * Aprobar justificación de ausencia.
     *
     * POST /api/admin/attendance/{id}/approve-justification
     */
    public function approveJustification(Request $request, int $id): JsonResponse
    {
        $attendance = Attendance::where('tenant_id', current_tenant_id())
            ->findOrFail($id);

        $attendance->update([
            'justification_status' => 'approved',
            'status'               => 'excused',
            'reviewed_by'          => $request->user()->id,
            'reviewed_at'          => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Justificación aprobada.',
            'data'    => new AttendanceResource($attendance->fresh()),
        ]);
    }

    /**
     * Rechazar justificación de ausencia.
     *
     * POST /api/admin/attendance/{id}/reject-justification
     */
    public function rejectJustification(Request $request, int $id): JsonResponse
    {
        $request->validate(['reason' => ['sometimes', 'string', 'max:500']]);

        $attendance = Attendance::where('tenant_id', current_tenant_id())
            ->findOrFail($id);

        $attendance->update([
            'justification_status' => 'rejected',
            'reviewed_by'          => $request->user()->id,
            'reviewed_at'          => now(),
            'notes'                => $request->input('reason'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Justificación rechazada.',
            'data'    => new AttendanceResource($attendance->fresh()),
        ]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Determina qué acción debe mostrar la app móvil según el estado actual.
     * La UI de Flutter usa esto para saber qué botón renderizar.
     */
    private function determineNextAction(?Attendance $attendance): array
    {
        if (! $attendance || is_null($attendance->clock_in_at)) {
            return ['action' => 'clock_in', 'label' => 'Marcar Entrada', 'icon' => 'login'];
        }

        if (is_null($attendance->break_start_at) && is_null($attendance->clock_out_at)) {
            return [
                'action'   => 'break_or_clock_out',
                'actions'  => [
                    ['action' => 'break_start', 'label' => 'Iniciar Descanso',  'icon' => 'coffee'],
                    ['action' => 'clock_out',   'label' => 'Marcar Salida',     'icon' => 'logout'],
                ],
                'label'    => 'En jornada',
            ];
        }

        if (! is_null($attendance->break_start_at) && is_null($attendance->break_end_at)) {
            return ['action' => 'break_end', 'label' => 'Terminar Descanso', 'icon' => 'play_arrow'];
        }

        if (! is_null($attendance->break_end_at) && is_null($attendance->clock_out_at)) {
            return ['action' => 'clock_out', 'label' => 'Marcar Salida', 'icon' => 'logout'];
        }

        // Jornada completa
        return ['action' => 'completed', 'label' => 'Jornada Completada', 'icon' => 'check_circle'];
    }

    /**
     * IDs de los colaboradores que supervisa el usuario: los asignados a las
     * sucursales donde él es supervisor (branches.supervisor_id).
     *
     * @return array<int, int>
     */
    private function teamUserIds(User $supervisor): array
    {
        return User::where('id', '!=', $supervisor->id)
            ->whereHas('branches', fn ($q) => $q->where('branches.supervisor_id', $supervisor->id))
            ->pluck('id')
            ->all();
    }

    /**
     * Convierte excepciones del servicio en respuestas JSON apropiadas.
     */
    private function handleAttendanceException(\Exception $e): JsonResponse
    {
        $code = $e->getCode();

        // Usar el código de la excepción como HTTP status cuando es válido
        $httpStatus = in_array($code, [400, 404, 409, 422]) ? $code : 500;

        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'code'    => match ($httpStatus) {
                404     => 'NOT_FOUND',
                409     => 'CONFLICT',
                422     => 'GEOFENCE_VIOLATION',
                default => 'SERVER_ERROR',
            },
        ], $httpStatus);
    }
}
