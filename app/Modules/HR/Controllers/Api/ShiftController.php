<?php

namespace App\Modules\HR\Controllers\Api;

use App\Modules\Attendance\Services\ScheduleResolver;
use App\Modules\Core\Controllers\Controller;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Shift;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ShiftController
 * Turnos fijos y rotativos con tolerancia de tardanza.
 *
 * Admin/RRHH:  /api/admin/shifts (CRUD)
 * Empleado:    /api/my-shifts, /api/schedule
 */
class ShiftController extends Controller
{
    /** Listar turnos de la empresa */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'type'      => ['sometimes', 'in:fixed,rotating'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $shifts = Shift::query()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->withCount(['employeeShifts as employees_count' => fn ($q) => $q->current()])
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $shifts->map(fn (Shift $s) => $this->formatShift($s)),
        ]);
    }

    /** Ver un turno */
    public function show(int $id): JsonResponse
    {
        $shift = Shift::withCount(['employeeShifts as employees_count' => fn ($q) => $q->current()])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $this->formatShift($shift),
        ]);
    }

    /** Crear turno */
    public function store(Request $request): JsonResponse
    {
        $shift = Shift::create($this->validateShift($request));
        app(\App\Modules\Core\Services\AuditLogger::class)->logCreated('shift.created', $shift, ['name' => $shift->name]);

        return response()->json([
            'success' => true,
            'message' => 'Turno creado correctamente.',
            'data'    => $this->formatShift($shift->fresh()),
        ], 201);
    }

    /** Actualizar turno */
    public function update(Request $request, int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);
        app(\App\Modules\Core\Services\AuditLogger::class)->updateWithAudit('shift.updated', $shift, $this->validateShift($request, $shift), ['name' => $shift->name]);

        return response()->json([
            'success' => true,
            'message' => 'Turno actualizado correctamente.',
            'data'    => $this->formatShift($shift->fresh()),
        ]);
    }

    /** Eliminar turno (soft delete). No se permite si tiene asignaciones vigentes. */
    public function destroy(int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);

        if ($shift->employeeShifts()->current()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'El turno tiene colaboradores asignados. Reasígnalos antes de eliminarlo.',
                'code'    => 'SHIFT_IN_USE',
            ], 409);
        }

        $shift->delete();
        app(\App\Modules\Core\Services\AuditLogger::class)->log('shift.deleted', $shift, ['name' => $shift->name]);

        return response()->json([
            'success' => true,
            'message' => 'Turno eliminado.',
        ]);
    }

    // =========================================================================
    // EMPLEADO
    // =========================================================================

    /**
     * Turnos vigentes del empleado autenticado.
     * GET /api/my-shifts
     */
    public function myShifts(Request $request): JsonResponse
    {
        $assignments = EmployeeShift::where('user_id', $request->user()->id)
            ->current()
            ->with(['shift', 'branch:id,name,code'])
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $assignments->map(fn (EmployeeShift $a) => [
                'id'                   => $a->id,
                'effective_from'       => $a->effective_from?->toDateString(),
                'effective_until'      => $a->effective_until?->toDateString(),
                'rotation_anchor_date' => $a->rotation_anchor_date?->toDateString(),
                'branch'               => $a->branch ? ['id' => $a->branch->id, 'name' => $a->branch->name] : null,
                'shift'                => $a->shift ? $this->formatShift($a->shift) : null,
            ]),
        ]);
    }

    /**
     * Calendario del empleado: qué turno le toca cada día del rango.
     * GET /api/schedule?from=2026-10-01&to=2026-10-31
     */
    public function schedule(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to'   => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $tz   = tenant_timezone();
        $from = Carbon::parse($request->input('from', now($tz)->startOfMonth()->toDateString()));
        $to   = Carbon::parse($request->input('to', $from->copy()->endOfMonth()->toDateString()));

        if ($from->diffInDays($to) > 92) {
            return response()->json([
                'success' => false,
                'message' => 'El rango máximo es de 92 días.',
                'code'    => 'RANGE_TOO_LARGE',
            ], 422);
        }

        $assignments = EmployeeShift::where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->where('effective_from', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_until')
                ->orWhere('effective_until', '>=', $from->toDateString()))
            ->with(['shift', 'branch:id,name'])
            ->get();

        $days = [];
        $schedules = app(ScheduleResolver::class);
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $resolved = $schedules->resolve($assignments, $day, ScheduleResolver::LEGACY_FIRST_WORKING);
            $assignment = $resolved->works ? $resolved->assignment : null;

            $days[] = [
                'date'       => $day->toDateString(),
                'is_workday' => (bool) $assignment,
                'shift'      => $assignment ? [
                    'id'         => $assignment->shift->id,
                    'name'       => $assignment->shift->name,
                    'start_time' => substr($assignment->shift->forDate($day)->start_time, 0, 5),
                    'end_time'   => substr($assignment->shift->forDate($day)->end_time, 0, 5),
                    'color'      => $assignment->shift->color,
                ] : null,
                'branch'     => $assignment?->branch?->name,
            ];
        }

        return response()->json([
            'success' => true,
            'period'  => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'data'    => $days,
        ]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function validateShift(Request $request, ?Shift $shift = null): array
    {
        $required = $shift ? 'sometimes' : 'required';
        $type     = $request->input('type', $shift?->type ?? 'fixed');

        $validated = $request->validate([
            'name'                   => [$required, 'string', 'max:100'],
            'code'                   => ['sometimes', 'nullable', 'string', 'max:50',
                Rule::unique('shifts', 'code')
                    ->where('tenant_id', current_tenant_id())
                    ->whereNull('deleted_at')
                    ->ignore($shift?->id)],
            'type'                   => ['sometimes', 'in:fixed,rotating'],
            'start_time'             => [$required, 'date_format:H:i'],
            'end_time'               => [$required, 'date_format:H:i'],
            'grace_period_minutes'   => ['sometimes', 'integer', 'between:0,240'],
            'break_allowed'          => ['sometimes', 'boolean'],
            'break_duration_minutes' => ['sometimes', 'integer', 'between:0,480'],
            'working_days'           => [$type === 'fixed' && ! $shift ? 'required' : 'sometimes', 'array', 'min:1'],
            'working_days.*'         => ['integer', 'between:0,6', 'distinct'],
            'rotation_work_days'     => [$type === 'rotating' && ! $shift?->rotation_work_days ? 'required' : 'sometimes', 'nullable', 'integer', 'between:1,60'],
            'rotation_rest_days'     => [$type === 'rotating' && ! $shift?->rotation_rest_days ? 'required' : 'sometimes', 'nullable', 'integer', 'between:1,60'],
            'daily_hours'            => ['sometimes', 'numeric', 'between:0.5,24'],
            'color'                  => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active'              => ['sometimes', 'boolean'],
            'description'            => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        // El turno cruza la medianoche si la salida es antes que la entrada (ej: 22:00 → 06:00)
        $start = $validated['start_time'] ?? ($shift ? substr($shift->start_time, 0, 5) : null);
        $end   = $validated['end_time']   ?? ($shift ? substr($shift->end_time, 0, 5) : null);
        if ($start && $end) {
            $validated['crosses_midnight'] = $end <= $start;
        }

        return $validated;
    }

    private function formatShift(Shift $shift): array
    {
        return [
            'id'                     => $shift->id,
            'name'                   => $shift->name,
            'code'                   => $shift->code,
            'type'                   => $shift->type ?? 'fixed',
            'start_time'             => substr($shift->start_time, 0, 5),
            'end_time'               => substr($shift->end_time, 0, 5),
            'crosses_midnight'       => (bool) $shift->crosses_midnight,
            'grace_period_minutes'   => (int) $shift->grace_period_minutes,
            'break_allowed'          => (bool) $shift->break_allowed,
            'break_duration_minutes' => (int) $shift->break_duration_minutes,
            'working_days'           => $shift->working_days,
            'rotation_work_days'     => $shift->rotation_work_days,
            'rotation_rest_days'     => $shift->rotation_rest_days,
            'daily_hours'            => (float) $shift->daily_hours,
            'color'                  => $shift->color,
            'is_active'              => (bool) $shift->is_active,
            'description'            => $shift->description,
            'employees_count'        => $shift->employees_count ?? null,
        ];
    }
}
