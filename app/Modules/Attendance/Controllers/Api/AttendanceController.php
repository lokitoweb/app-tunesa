<?php

namespace App\Modules\Attendance\Controllers\Api;

use App\Modules\Attendance\Exceptions\AttendanceException;
use App\Modules\Core\Controllers\Controller;
use App\Modules\Attendance\Requests\BreakRequest;
use App\Modules\Attendance\Requests\ClockInRequest;
use App\Modules\Attendance\Requests\ClockOutRequest;
use App\Modules\Attendance\Resources\AttendanceResource;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Attendance\Services\Attendance\PunchData;
use App\Modules\Attendance\Services\Attendance\PunchResult;
use App\Modules\Attendance\Services\AttendanceService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Validation\Rule;
use App\Modules\Core\Rules\SafeUpload;

/**
 * AttendanceController
 *
 * Expone los 4 endpoints del ciclo de marcación y los reportes de asistencia.
 * Delega TODA la lógica al AttendanceService.
 * Este controlador solo: valida → delega → responde.
 *
 * Endpoints del ciclo de marcación (todos pasan por AttendanceService::punch,
 * que escribe la jornada y la bitácora inmutable attendance_punches):
 *   POST /api/attendance/clock-in      → Entrada (GPS + selfie según la empresa)
 *   POST /api/attendance/break-start   → Inicio de descanso
 *   POST /api/attendance/break-end     → Fin de descanso
 *   POST /api/attendance/clock-out     → Salida
 * Sincronización offline en lote: AttendanceSyncController.
 */
class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendanceService,
    ) {}

    // =========================================================================
    // CICLO DE MARCACIÓN (entrada, descanso, salida)
    // =========================================================================

    /**
     * Entrada. Multipart: latitude, longitude, photo (según selfie_required),
     * accuracy_meters, is_mocked, face_detected, device_id, device_os,
     * app_version, offline_recorded_at, device_time, client_uuid.
     *
     * 201 creada · 200 duplicate:true (mismo client_uuid) · 409/422 AttendanceException
     */
    public function clockIn(ClockInRequest $request): JsonResponse
    {
        return $this->punch($request->user(), $request->toPunchData('clock_in'));
    }

    /** Inicio de descanso (mismos campos; foto opcional) */
    public function breakStart(BreakRequest $request): JsonResponse
    {
        return $this->punch($request->user(), $request->toPunchData('break_start'));
    }

    /** Fin de descanso */
    public function breakEnd(BreakRequest $request): JsonResponse
    {
        return $this->punch($request->user(), $request->toPunchData('break_end'));
    }

    /** Salida: calcula horas trabajadas */
    public function clockOut(ClockOutRequest $request): JsonResponse
    {
        return $this->punch($request->user(), $request->toPunchData('clock_out'));
    }

    private function punch(User $employee, PunchData $data): JsonResponse
    {
        try {
            $result = $this->attendanceService->punch($employee, $data);
        } catch (\Exception $e) {
            return $this->handleAttendanceException($e);
        }

        return response()->json([
            'success'   => true,
            'duplicate' => $result->duplicate,
            'message'   => $result->duplicate ? 'Esta marcación ya estaba registrada.' : self::successMessage($result),
            'data'      => new AttendanceResource($result->attendance),
            'punch'     => $result->punch->toApi(),
        ], $data->type === 'clock_in' && ! $result->duplicate ? 201 : 200);
    }

    public static function successMessage(PunchResult $result): string
    {
        $a = $result->attendance;

        return match ($result->punch->type) {
            'clock_in'    => $a->clock_in_within_geofence
                ? '✅ Entrada registrada correctamente.'
                : "⚠️ Entrada registrada fuera del radio de la sucursal ({$a->clock_in_distance_meters} m). RRHH la revisará.",
            'break_start' => '☕ Descanso iniciado.',
            'break_end'   => '🔄 Regreso de descanso registrado.',
            'clock_out'   => '🏁 Salida registrada. Jornada de hoy: ' . number_format($a->regularHours(), 1) . ' h.'
                . ((float) $a->overtime_hours > 0 ? ' +' . number_format((float) $a->overtime_hours, 1) . ' h extra por aprobar.' : '')
                . ($a->clock_out_within_geofence ? '' : ' ⚠️ Marcada fuera del radio de la sucursal; RRHH la revisará.'),
        };
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

        // Turno programado hoy: sin turno (ni autorización) no se ofrece marcar entrada
        $schedule = app(\App\Modules\Attendance\Services\ScheduleGate::class)->statusFor($employee, $today);

        // Determinar el próximo paso disponible para la UI de la app
        $nextAction = $this->determineNextAction($attendance);
        if ((! $attendance || is_null($attendance->clock_in_at)) && ! $schedule['can_clock_in']) {
            $nextAction = ['action' => null, 'actions' => [], 'label' => $schedule['message'], 'code' => 'NOT_SCHEDULED'];
        }

        return response()->json([
            'success'     => true,
            'data'        => $attendance ? new AttendanceResource($attendance) : null,
            'next_action' => $nextAction,
            'schedule'    => $schedule,
            // Jornada abierta: hora de salida esperada (la app avisa si se sale antes y queda incompleta)
            'expected_clock_out' => $attendance?->clock_in_at && ! $attendance->clock_out_at
                ? AttendanceService::expectedClockOut($attendance, tenant_timezone(), (int) \App\Modules\Core\Models\TenantSetting::forTenant($employee->tenant)->lunch_minutes)->toIso8601String()
                : null,
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
        ['attendances' => $attendances, 'summary' => $summary] = self::monthFor($employee, (int) $year, (int) $month);

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

    /**
     * Jornadas del mes de una persona y su resumen (la app y el portal web muestran lo mismo).
     *
     * @return array{attendances: \Illuminate\Pagination\LengthAwarePaginator, summary: array<string, int|float>}
     */
    public static function monthFor(User $employee, int $year, int $month): array
    {
        // Mismo cálculo que el calendario del panel (registra faltas pendientes del mes)
        $calendar = app(\App\Modules\Attendance\Services\AbsenceService::class)->monthFor($employee, $year, $month);
        $states = collect($calendar['cells'])->filter()->countBy('state');

        $attendances = Attendance::where('user_id', $employee->id)
            ->forPeriod($year, $month)
            ->with(['branch', 'shift'])
            ->orderBy('work_date', 'desc')
            ->paginate(31); // Max días del mes

        return ['attendances' => $attendances, 'summary' => [
            'total_days'     => $attendances->total(),
            // Conteos del calendario: lo que ve RRHH en el panel es lo que ve la persona
            'present'        => (int) ($states['present'] ?? 0),
            'late'           => (int) ($states['late'] ?? 0),
            'absent'         => (int) ($states['absent'] ?? 0),
            'excused'        => (int) ($states['excused'] ?? 0),
            // Jornada (tope del turno) + horas extra aprobadas: lo mismo que paga la planilla
            'total_hours'    => round($attendances->sum(fn ($a) => $a->payableHours()), 1),
            'overtime_hours' => round($attendances->sum('overtime_hours'), 1),
            'late_minutes'   => $attendances->sum('late_minutes'),
        ]];
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

        // Personal activo que marca (mismos roles que el panel: User::CLOCKING_ROLES), para saber cuántos faltan
        $totalEmployees = \App\Modules\Core\Models\User::where('tenant_id', $tenantId)
            ->whereIn('role', \App\Modules\Core\Models\User::CLOCKING_ROLES)
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
            'branch_id' => ['sometimes', 'integer', Rule::exists('branches', 'id')->where('tenant_id', current_tenant_id())],
            'user_id'   => ['sometimes', 'integer', Rule::exists('users', 'id')->where('tenant_id', current_tenant_id())],
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

        // Mismos roles que el panel (User::CLOCKING_ROLES): la app y el panel dan el mismo total
        $activeEmployees = User::whereIn('role', User::CLOCKING_ROLES)->where('status', 'active')
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
                        hora($a->clock_in_at),
                        hora($a->clock_out_at),
                        $a->status,
                        $a->late_minutes,
                        $a->break_minutes,
                        $a->worked_hours,
                        $a->overtime_hours,
                        \App\Modules\Attendance\Exports\AttendanceExport::geofenceLabel($a->clock_in_at, $a->clock_in_within_geofence),
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

        $attendances = Attendance::whereIn('user_id', $this->teamUserIds($request->user(), activeOnly: false))
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
            'file'  => ['sometimes', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120', new SafeUpload],
        ]);

        $attendance = Attendance::where('user_id', $request->user()->id)->findOrFail($id);

        if ($attendance->justification_status === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Esta justificación ya fue aprobada.',
                'code'    => 'CONFLICT',
            ], 409);
        }

        $attendance->submitJustification($validated['notes'], $request->file('file'));

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

        $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:500']]);
        $attendance->reviewJustification($request->user(), 'approved', $request->input('note'));

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

        // Antes reemplazaba las notas (borraba el texto del colaborador): ahora se agregan
        $attendance->reviewJustification($request->user(), 'rejected', $request->input('reason'));

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
    public static function determineNextAction(?Attendance $attendance): array
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
     * Equipo del usuario (User::teamOf): colaboradores y supervisores de sus
     * sucursales, o todo el personal que marca si ve toda la asistencia (RRHH,
     * admin). Nunca él mismo. El reporte incluye también a los inactivos
     * (historial de quien ya no está).
     *
     * @return array<int, int>
     */
    private function teamUserIds(User $viewer, bool $activeOnly = true): array
    {
        return User::teamOf($viewer, $activeOnly)
            ->pluck('users.id')
            ->all();
    }

    /**
     * Convierte excepciones del servicio en respuestas JSON.
     *
     * AttendanceException trae su propio código (GEOFENCE_VIOLATION solo para
     * distancia, NO_BRANCH_ASSIGNED, ALREADY_CLOCKED_IN...) y su HTTP status.
     * Cualquier otra excepción es un error inesperado: 500 sin detalles internos.
     */
    private function handleAttendanceException(\Exception $e): JsonResponse
    {
        if ($e instanceof AttendanceException) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code'    => $e->errorCode,
            ], $e->status());
        }

        // Errores HTTP/validación/no encontrado: los renderiza el manejador global
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
            || $e instanceof \Illuminate\Validation\ValidationException
            || $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            throw $e;
        }

        report($e);

        return response()->json([
            'success' => false,
            'message' => 'No se pudo registrar la marcación. Intenta de nuevo en unos minutos.',
            'code'    => 'SERVER_ERROR',
        ], 500);
    }
}
