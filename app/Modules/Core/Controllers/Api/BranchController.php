<?php

namespace App\Modules\Core\Controllers\Api;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Services\BranchStaffing;
use App\Modules\Attendance\Services\GeofenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BranchController
 * CRUD de sucursales y endpoint para el mapa interactivo del dashboard.
 */
class BranchController extends Controller
{
    public function __construct(
        private readonly GeofenceService $geofence,
    ) {}

    /** Listar sucursales del tenant */
    public function index(Request $request): JsonResponse
    {
        $branches = Branch::where('tenant_id', current_tenant_id())
            ->when($request->boolean('active_only', false), fn($q) => $q->where('is_active', true))
            ->with('supervisor:id,first_name,last_name,email')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $branches->map(fn($b) => $this->formatBranch($b)),
        ]);
    }

    /** Detalle de una sucursal */
    public function show(int $id): JsonResponse
    {
        $branch = Branch::where('tenant_id', current_tenant_id())
            ->with('supervisor', 'employees')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $this->formatBranch($branch, detailed: true),
        ]);
    }

    /** Crear sucursal */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                   => ['required', 'string', 'max:150'],
            'code'                   => ['sometimes', 'nullable', 'string', 'max:50'],
            'address'                => ['sometimes', 'nullable', 'string'],
            'city'                   => ['sometimes', 'nullable', 'string', 'max:100'],
            'latitude'               => ['required', 'numeric', 'between:-90,90'],
            'longitude'              => ['required', 'numeric', 'between:-180,180'],
            'allowed_radius_meters'  => ['required', 'integer', 'min:10', 'max:5000'],
            'supervisor_id'          => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'timezone'               => ['sometimes', 'nullable', 'timezone'],
            'is_active'              => ['sometimes', 'boolean'],
        ]);

        // Validar que las coordenadas son geográficamente válidas
        if (! $this->geofence->areValidCoordinates(
            $validated['latitude'],
            $validated['longitude'],
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Las coordenadas GPS proporcionadas no son válidas.',
            ], 422);
        }

        $branch = Branch::create(array_merge($validated, [
            'tenant_id' => current_tenant_id(),
        ]));
        app(\App\Modules\Core\Services\AuditLogger::class)->logCreated('branch.created', $branch, ['name' => $branch->name]);

        return response()->json([
            'success' => true,
            'message' => "Sucursal '{$branch->name}' creada con geocerca de {$branch->allowed_radius_meters}m.",
            'data'    => $this->formatBranch($branch),
        ], 201);
    }

    /** Actualizar sucursal */
    public function update(Request $request, int $id): JsonResponse
    {
        $branch = Branch::where('tenant_id', current_tenant_id())->findOrFail($id);

        $validated = $request->validate([
            'name'                   => ['sometimes', 'string', 'max:150'],
            'code'                   => ['sometimes', 'nullable', 'string', 'max:50'],
            'address'                => ['sometimes', 'nullable', 'string'],
            'latitude'               => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude'              => ['sometimes', 'numeric', 'between:-180,180'],
            'allowed_radius_meters'  => ['sometimes', 'integer', 'min:10', 'max:5000'],
            'supervisor_id'          => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'is_active'              => ['sometimes', 'boolean'],
        ]);

        app(\App\Modules\Core\Services\AuditLogger::class)->updateWithAudit('branch.updated', $branch, $validated, ['name' => $branch->name]);

        return response()->json([
            'success' => true,
            'message' => 'Sucursal actualizada.',
            'data'    => $this->formatBranch($branch->fresh()),
        ]);
    }

    /** Eliminar sucursal (soft delete) */
    public function destroy(int $id): JsonResponse
    {
        $branch = Branch::where('tenant_id', current_tenant_id())->findOrFail($id);
        $branch->delete();
        app(\App\Modules\Core\Services\AuditLogger::class)->log('branch.deleted', $branch, ['name' => $branch->name]);

        return response()->json([
            'success' => true,
            'message' => 'Sucursal eliminada.',
        ]);
    }

    /**
     * Sucursales asignadas al empleado autenticado (para la app móvil).
     * GET /api/my-branches
     */
    public function myBranches(Request $request): JsonResponse
    {
        $employee = $request->user();
        $today    = now()->toDateString();

        $branches = $employee->branches()
            // Sin fecha de inicio (asignada desde el panel) = vigente desde siempre
            ->where(function ($q) use ($today) {
                $q->whereNull('employee_branch.assigned_from')
                    ->orWhere('employee_branch.assigned_from', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('employee_branch.assigned_until')
                    ->orWhere('employee_branch.assigned_until', '>=', $today);
            })
            ->where('branches.is_active', true)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $branches->map(fn($b) => $this->formatBranch($b)),
        ]);
    }

    /**
     * Datos para el mapa interactivo del panel web.
     * Devuelve sucursales con conteo de asistencias del día.
     *
     * GET /api/admin/dashboard/map
     */
    public function mapData(Request $request): JsonResponse
    {
        $today = now(tenant_timezone())->toDateString();

        $branches = Branch::where('tenant_id', current_tenant_id())
            ->where('is_active', true)
            ->withCount([
                'attendances as present_count'  => fn($q) => $q->where('work_date', $today)->whereNotNull('clock_in_at'),
                'attendances as late_count'     => fn($q) => $q->where('work_date', $today)->where('status', 'late'),
                'employees as total_employees',
            ])
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $branches->map(fn($b) => [
                'id'                    => $b->id,
                'name'                  => $b->name,
                'code'                  => $b->code,
                'latitude'              => (float) $b->latitude,
                'longitude'             => (float) $b->longitude,
                'allowed_radius_meters' => $b->allowed_radius_meters,
                'total_employees'       => $b->total_employees,
                'present_count'         => $b->present_count,
                'late_count'            => $b->late_count,
                'absent_count'          => $b->total_employees - $b->present_count,
                'attendance_rate'       => $b->total_employees > 0
                    ? round(($b->present_count / $b->total_employees) * 100, 1)
                    : 0,
            ]),
        ]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function formatBranch(Branch $branch, bool $detailed = false): array
    {
        $data = [
            'id'                    => $branch->id,
            'name'                  => $branch->name,
            'code'                  => $branch->code,
            'address'               => $branch->address,
            'city'                  => $branch->city,
            'geofence' => [
                'latitude'              => (float) $branch->latitude,
                'longitude'             => (float) $branch->longitude,
                'allowed_radius_meters' => $branch->allowed_radius_meters,
                // Zona de borde y precisión efectivas (la app las usa en su verificación previa)
                'tolerance_meters'        => $branch->edgeToleranceMeters(),
                'max_gps_accuracy_meters' => $branch->maxGpsAccuracy(TenantSetting::forTenant($branch->tenant)),
            ],
            'timezone'              => $branch->getEffectiveTimezone(),
            'is_active'             => $branch->is_active,
            'supervisor' => $branch->relationLoaded('supervisor') && $branch->supervisor ? [
                'id'       => $branch->supervisor->id,
                'name'     => $branch->supervisor->full_name,
                'email'    => $branch->supervisor->email,
            ] : null,
        ];

        if ($detailed && $branch->relationLoaded('employees')) {
            $data['employees_count'] = $branch->employees->count();
        }
        if ($detailed) {
            // Plantilla: plazas previstas por cargo frente al personal activo
            $data['staffing'] = app(BranchStaffing::class)->forBranch($branch);
        }

        return $data;
    }
}
