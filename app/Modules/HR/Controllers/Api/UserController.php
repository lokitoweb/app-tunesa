<?php

namespace App\Modules\HR\Controllers\Api;

use App\Modules\Core\Services\CredentialProvisioner;
use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Resources\UserResource;
use App\Modules\Core\Models\Branch;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\Core\Models\User;
use App\Modules\Attendance\Services\GeofenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * UserController (Admin)
 * Gestión de empleados para Company Admin y HR Manager.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly GeofenceService $geofence,
    ) {}

    /** Listar empleados del tenant con filtros */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search'    => ['sometimes', 'string', 'max:100'],
            'branch_id' => ['sometimes', 'integer'],
            'role'      => ['sometimes', 'string'],
            'status'    => ['sometimes', 'string', 'in:active,inactive,suspended'],
        ]);

        $employees = User::where('tenant_id', current_tenant_id())
            ->where('role', '!=', 'super_admin')
            ->when($request->search, fn($q) =>
                $q->where(fn($sq) =>
                    $sq->where('first_name', 'like', "%{$request->search}%")
                       ->orWhere('last_name',  'like', "%{$request->search}%")
                       ->orWhere('email',      'like', "%{$request->search}%")
                       ->orWhere('employee_code', 'like', "%{$request->search}%")
                )
            )
            ->when($request->role,   fn($q) => $q->where('role',   $request->role))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->branch_id, fn($q) =>
                $q->whereHas('branches', fn($bq) => $bq->where('branches.id', $request->branch_id))
            )
            ->with(['tenant', 'branches'])
            ->orderBy('last_name')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => UserResource::collection($employees),
            'meta'    => ['total' => $employees->total()],
        ]);
    }

    /** Ver detalle de un empleado */
    public function show(int $id): JsonResponse
    {
        $user = User::where('tenant_id', current_tenant_id())
            ->with(['tenant', 'branches', 'employeeShifts.shift', 'employeeShifts.branch'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => new UserResource($user),
        ]);
    }

    /** Crear empleado */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name'    => ['required', 'string', 'max:100'],
            'last_name'     => ['required', 'string', 'max:100'],
            // Sin correo: se genera [DNI]@dominio. La contraseña siempre la genera el sistema.
            'email'           => ['required_without:document_number', 'nullable', 'email', 'unique:users,email'],
            'document_type'   => ['sometimes', Rule::in(['dni', 'ce', 'passport'])],
            'document_number' => ['required_without:email', 'nullable', 'string', 'max:30'],
            'role'          => ['required', 'in:company_admin,hr_manager,manager,accountant,supervisor,employee'],
            'position'      => ['sometimes', 'nullable', 'string', 'max:150'],
            'position_id'   => ['sometimes', 'nullable', 'integer', Rule::exists('positions', 'id')->where('tenant_id', current_tenant_id())],
            'department'    => ['sometimes', 'nullable', 'string', 'max:100'],
            'employee_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'base_salary'   => ['sometimes', 'numeric', 'min:0'],
            'hire_date'     => ['sometimes', 'date'],
            'contract_type' => ['sometimes', 'in:full_time,part_time,contractor,intern'],
            'phone'         => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        abort_unless($request->user()->canAssignRole($validated['role']), 403, 'No puedes crear usuarios con un rol igual o superior al tuyo.');

        $credentials = app(CredentialProvisioner::class);
        $validated['email'] = ($validated['email'] ?? null)
            ?: $credentials->emailFor($request->user()->tenant, $validated['document_number']);
        $password = $credentials->temporaryPassword();

        // Contraseña temporal: vence en 72 h y se debe cambiar en el primer ingreso
        $user = User::create(array_merge($validated, [
            'tenant_id' => current_tenant_id(),
            'status'    => 'active',
            'password'  => $password,
        ]));
        app(\App\Modules\Onboarding\Services\OnboardingService::class)->startFor($user);
        $user->forceFill($credentials->temporaryAttributes($password))->save();

        return response()->json([
            'success' => true,
            'message' => 'Empleado creado correctamente. Entrega la contraseña temporal: no se volverá a mostrar.',
            'data'    => new UserResource($user->load('tenant')),
            'credentials' => ['email' => $user->email, 'temporary_password' => $password],
        ], 201);
    }

    /** Actualizar empleado */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::where('tenant_id', current_tenant_id())->findOrFail($id);

        $validated = $request->validate([
            'first_name'    => ['sometimes', 'string', 'max:100'],
            'last_name'     => ['sometimes', 'string', 'max:100'],
            'position'      => ['sometimes', 'nullable', 'string', 'max:150'],
            'position_id'   => ['sometimes', 'nullable', 'integer', Rule::exists('positions', 'id')->where('tenant_id', current_tenant_id())],
            'department'    => ['sometimes', 'nullable', 'string'],
            'base_salary'   => ['sometimes', 'numeric', 'min:0'],
            'status'        => ['sometimes', 'in:active,inactive,suspended'],
            'contract_type' => ['sometimes', 'in:full_time,part_time,contractor,intern'],
            'phone'         => ['sometimes', 'nullable', 'string'],
        ]);

        abort_unless($request->user()->canManage($user), 403, 'No puedes editar a un usuario de rango igual o superior.');

        app(\App\Modules\Core\Services\AuditLogger::class)->updateWithAudit('employee.updated', $user, $validated, ['name' => $user->full_name]);

        return response()->json([
            'success' => true,
            'message' => 'Empleado actualizado.',
            'data'    => new UserResource($user->fresh()->load('tenant')),
        ]);
    }

    /** Desactivar empleado (soft) */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = User::where('tenant_id', current_tenant_id())->findOrFail($id);
        abort_unless($request->user()->canManage($user) && $request->user()->id !== $user->id, 403);
        app(\App\Modules\Core\Services\AuditLogger::class)->updateWithAudit('employee.deleted', $user, ['status' => 'inactive'], ['name' => $user->full_name]);
        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'Empleado desactivado.',
        ]);
    }

    /** Asignar sucursal a un empleado */
    public function assignBranch(Request $request, int $id): JsonResponse
    {
        $user = User::where('tenant_id', current_tenant_id())->findOrFail($id);

        $request->validate([
            'branch_id'      => ['required', 'integer', 'exists:branches,id'],
            'is_primary'     => ['sometimes', 'boolean'],
            'assigned_from'  => ['sometimes', 'date'],
            'assigned_until' => ['sometimes', 'nullable', 'date', 'after:assigned_from'],
        ]);

        // Si se asigna como principal, quitar el principal anterior
        if ($request->boolean('is_primary', false)) {
            $user->branches()->updateExistingPivot(
                $user->branches()->wherePivot('is_primary', true)->pluck('branches.id'),
                ['is_primary' => false]
            );
        }

        $user->branches()->syncWithoutDetaching([
            $request->branch_id => [
                'is_primary'     => $request->boolean('is_primary', false),
                'assigned_from'  => $request->input('assigned_from', today()),
                'assigned_until' => $request->input('assigned_until'),
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Sucursal asignada correctamente.',
        ]);
    }

    /** Asignar turno a un empleado */
    public function assignShift(Request $request, int $id): JsonResponse
    {
        $user = User::where('tenant_id', current_tenant_id())->findOrFail($id);

        $request->validate([
            'shift_id'       => ['required', 'integer', 'exists:shifts,id'],
            'branch_id'      => ['required', 'integer', 'exists:branches,id'],
            'effective_from' => ['required', 'date'],
            'effective_until'=> ['sometimes', 'nullable', 'date', 'after:effective_from'],
            'notes'          => ['sometimes', 'nullable', 'string'],
        ]);

        // Desactivar el turno activo anterior
        EmployeeShift::where('user_id', $user->id)
            ->where('is_active', true)
            ->update(['is_active' => false, 'effective_until' => now()->toDateString()]);

        // Crear nueva asignación
        $employeeShift = EmployeeShift::create([
            'tenant_id'      => current_tenant_id(),
            'user_id'        => $user->id,
            'shift_id'       => $request->shift_id,
            'branch_id'      => $request->branch_id,
            'effective_from' => $request->effective_from,
            'effective_until'=> $request->effective_until,
            'assigned_by'    => $request->user()->id,
            'notes'          => $request->notes,
            'is_active'      => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Turno asignado correctamente.',
            'data'    => $employeeShift->load('shift', 'branch'),
        ]);
    }

    /** Vista del supervisor: empleados de su área */
    public function myTeam(Request $request): JsonResponse
    {
        $supervisor = $request->user();

        // Supervisor: colaboradores y supervisores de sus sucursales; RRHH/admin
        // (attendance.view_all): todo el personal que marca. Mismo equipo que attendance/live.
        $employees = User::where('tenant_id', current_tenant_id())
            ->teamOf($supervisor)
            ->with(['branches'])
            ->get();

        return response()->json([
            'success' => true,
            'data'    => UserResource::collection($employees),
        ]);
    }
}
