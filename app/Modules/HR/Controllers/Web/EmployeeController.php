<?php

namespace App\Modules\HR\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\Shift;
use App\Modules\Core\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * EmployeeController (Web)
 *
 * Gestión completa del personal: alta, edición, asignación de sucursales,
 * horarios de turnos, y control de acceso.
 */
class EmployeeController extends Controller
{
    /**
     * Listado de empleados con filtros avanzados y paginación.
     */
    public function index(Request $request): View
    {
        $tenantId = current_tenant_id_or_null();

        // Vista global: usuarios de empresas, no los Super Admin de la plataforma
        $query = User::forTenant($tenantId)
            ->whereNotNull('tenant_id')
            ->with(['branches', 'employeeShifts.shift'])
            ->orderBy('first_name');

        // Filtro por término de búsqueda (nombre, email, código, documento)
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('employee_code', 'like', "%{$search}%")
                  ->orWhere('document_number', 'like', "%{$search}%");
            });
        }

        // Filtro por rol
        if ($role = $request->input('role')) {
            $query->where('role', $role);
        }

        // Filtro por estado
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Filtro por sucursal
        if ($branchId = $request->input('branch_id')) {
            $query->whereHas('branches', function ($q) use ($branchId) {
                $q->where('branches.id', $branchId);
            });
        }

        $employees = $query->paginate(15)->withQueryString();
        $branches = Branch::forTenant($tenantId)->where('is_active', true)->get();

        return view('employees.index', compact('employees', 'branches'));
    }

    /**
     * Muestra el formulario para crear un nuevo empleado.
     */
    public function create(): View
    {
        $tenantId = current_tenant_id_or_null();
        $branches = Branch::forTenant($tenantId)->where('is_active', true)->get();
        $shifts = Shift::forTenant($tenantId)->where('is_active', true)->get();

        return view('employees.create', compact('branches', 'shifts'));
    }

    /**
     * Almacena un nuevo empleado en la base de datos con asignaciones.
     */
    public function store(Request $request): RedirectResponse
    {
        $tenantId = current_tenant_id_or_null();

        $validated = $request->validate([
            'first_name'      => ['required', 'string', 'max:100'],
            'last_name'       => ['required', 'string', 'max:100'],
            'email'           => [
                'required', 'email', 'max:150',
                Rule::unique('users')->where(fn($q) => $q->where('tenant_id', $tenantId))
            ],
            'password'        => ['required', 'string', 'min:8'],
            'role'            => ['required', 'in:company_admin,hr_manager,supervisor,employee'],
            'document_type'   => ['required', Rule::in(['dni', 'ce', 'passport'])],
            'document_number' => ['required', 'string', 'max:30'],
            'phone'           => ['nullable', 'string', 'max:30'],
            'employee_code'   => ['nullable', 'string', 'max:50'],
            'position'        => ['nullable', 'string', 'max:100'],
            'department'      => ['nullable', 'string', 'max:100'],
            'base_salary'     => ['nullable', 'numeric', 'min:0'],
            'hire_date'       => ['nullable', 'date'],
            'branch_ids'      => ['required', 'array', 'min:1'],
            'branch_ids.*'    => ['integer', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'primary_branch_id' => ['required', 'integer', 'in_array:branch_ids.*'],
            'shift_id'        => ['nullable', 'integer', Rule::exists('shifts', 'id')->where('tenant_id', $tenantId)],
            'avatar'          => ['nullable', 'image', 'max:2048'],
        ]);

        abort_unless(Auth::user()->canAssignRole($validated['role']), 403, 'No puedes crear usuarios con un rol igual o superior al tuyo.');

        DB::transaction(function () use ($validated, $tenantId, $request) {
            $avatarPath = null;
            if ($request->hasFile('avatar')) {
                $avatarPath = $request->file('avatar')->store('avatars', 'public');
            }

            // Crear usuario
            $user = User::create([
                'tenant_id'       => $tenantId,
                'first_name'      => $validated['first_name'],
                'last_name'       => $validated['last_name'],
                'email'           => $validated['email'],
                'password'        => Hash::make($validated['password']),
                'role'            => $validated['role'],
                'status'          => 'active',
                'document_type'   => $validated['document_type'],
                'document_number' => $validated['document_number'],
                'phone'           => $validated['phone'] ?? null,
                'employee_code'   => $validated['employee_code'] ?? 'EMP-' . strtoupper(substr(uniqid(), -6)),
                'position'        => $validated['position'] ?? null,
                'department'      => $validated['department'] ?? null,
                'base_salary'     => $validated['base_salary'] ?? 0,
                'hire_date'       => $validated['hire_date'] ?? Carbon::today(),
                'avatar_path'     => $avatarPath,
                // Contraseña temporal: se exige cambiarla en el primer ingreso
                'must_change_password' => true,
            ]);

            // Asignar sucursales (con bandera de sucursal principal)
            $branchesToSync = [];
            foreach ($validated['branch_ids'] as $bId) {
                $branchesToSync[$bId] = ['is_primary' => ($bId == $validated['primary_branch_id'])];
            }
            $user->branches()->sync($branchesToSync);
            app(\App\Modules\Core\Services\AuditLogger::class)->log('employee.created', $user, ['name' => $user->full_name, 'role' => $user->role]);

            // Asignar turno inicial si fue provisto
            if (!empty($validated['shift_id'])) {
                EmployeeShift::create([
                    'tenant_id'            => $tenantId,
                    'user_id'              => $user->id,
                    'shift_id'             => $validated['shift_id'],
                    'branch_id'            => $validated['primary_branch_id'],
                    'effective_from'       => Carbon::today()->toDateString(),
                    'rotation_anchor_date' => Carbon::today()->toDateString(),
                    'assigned_by'          => Auth::id(),
                    'is_active'            => true,
                ]);
            }
        });

        return redirect()->route('employees.index')
            ->with('success', "Empleado {$validated['first_name']} {$validated['last_name']} creado exitosamente.");
    }

    /**
     * Muestra el formulario de edición de empleado.
     */
    public function edit(int $id): View
    {
        $tenantId = current_tenant_id_or_null();
        $employee = User::forTenant($tenantId)
            ->with(['branches', 'employeeShifts' => fn($q) => $q->where('is_active', true)->with('shift')])
            ->findOrFail($id);

        $branches = Branch::forTenant($tenantId)->where('is_active', true)->get();
        $shifts = Shift::forTenant($tenantId)->where('is_active', true)->get();
        $currentShift = $employee->employeeShifts->first();

        return view('employees.edit', compact('employee', 'branches', 'shifts', 'currentShift'));
    }

    /**
     * Actualiza la información del empleado.
     */
    public function update(Request $request, int $id): RedirectResponse
    {
        $tenantId = current_tenant_id_or_null();
        $employee = User::forTenant($tenantId)->findOrFail($id);

        $validated = $request->validate([
            'first_name'      => ['required', 'string', 'max:100'],
            'last_name'       => ['required', 'string', 'max:100'],
            'email'           => [
                'required', 'email', 'max:150',
                Rule::unique('users')->where(fn($q) => $q->where('tenant_id', $tenantId))->ignore($employee->id)
            ],
            'password'        => ['nullable', 'string', 'min:8'],
            'role'            => ['required', 'in:company_admin,hr_manager,supervisor,employee'],
            'status'          => ['required', 'in:active,inactive,suspended'],
            'document_type'   => ['required', Rule::in(['dni', 'ce', 'passport'])],
            'document_number' => ['required', 'string', 'max:30'],
            'phone'           => ['nullable', 'string', 'max:30'],
            'employee_code'   => ['nullable', 'string', 'max:50'],
            'position'        => ['nullable', 'string', 'max:100'],
            'department'      => ['nullable', 'string', 'max:100'],
            'base_salary'     => ['nullable', 'numeric', 'min:0'],
            'hire_date'       => ['nullable', 'date'],
            'branch_ids'      => ['required', 'array', 'min:1'],
            'branch_ids.*'    => ['integer', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'primary_branch_id' => ['required', 'integer', 'in_array:branch_ids.*'],
            'shift_id'        => ['nullable', 'integer', Rule::exists('shifts', 'id')->where('tenant_id', $tenantId)],
            'avatar'          => ['nullable', 'image', 'max:2048'],
        ]);

        $actor = Auth::user();
        abort_unless($actor->canManage($employee), 403, 'No puedes editar a un usuario de rango igual o superior.');
        abort_unless(
            $validated['role'] === $employee->role || $actor->canAssignRole($validated['role']),
            403,
            'No puedes asignar un rol igual o superior al tuyo.'
        );

        DB::transaction(function () use ($validated, $tenantId, $employee, $request) {
            $dataToUpdate = [
                'first_name'      => $validated['first_name'],
                'last_name'       => $validated['last_name'],
                'email'           => $validated['email'],
                'role'            => $validated['role'],
                'status'          => $validated['status'],
                'document_type'   => $validated['document_type'],
                'document_number' => $validated['document_number'],
                'phone'           => $validated['phone'] ?? null,
                'employee_code'   => $validated['employee_code'] ?? $employee->employee_code,
                'position'        => $validated['position'] ?? null,
                'department'      => $validated['department'] ?? null,
                'base_salary'     => $validated['base_salary'] ?? 0,
                'hire_date'       => $validated['hire_date'] ?? $employee->hire_date,
            ];

            if (!empty($validated['password'])) {
                $dataToUpdate['password'] = Hash::make($validated['password']);
                // Contraseña asignada por RRHH = temporal
                $dataToUpdate['must_change_password'] = true;
            }

            if ($request->hasFile('avatar')) {
                if ($employee->avatar_path) {
                    Storage::disk('public')->delete($employee->avatar_path);
                }
                $dataToUpdate['avatar_path'] = $request->file('avatar')->store('avatars', 'public');
            }

            app(\App\Modules\Core\Services\AuditLogger::class)->updateWithAudit('employee.updated', $employee, $dataToUpdate, ['name' => $employee->full_name]);

            // Sincronizar sucursales
            $branchesToSync = [];
            foreach ($validated['branch_ids'] as $bId) {
                $branchesToSync[$bId] = ['is_primary' => ($bId == $validated['primary_branch_id'])];
            }
            $employee->branches()->sync($branchesToSync);

            // Cambiar de turno solo si es distinto al vigente
            $current = EmployeeShift::where('user_id', $employee->id)->current()->first();
            if (! empty($validated['shift_id']) && $current?->shift_id !== (int) $validated['shift_id']) {
                EmployeeShift::where('user_id', $employee->id)->current()
                    ->update(['is_active' => false, 'effective_until' => Carbon::yesterday()->toDateString()]);

                EmployeeShift::create([
                    'tenant_id'            => $employee->tenant_id,
                    'user_id'              => $employee->id,
                    'shift_id'             => $validated['shift_id'],
                    'branch_id'            => $validated['primary_branch_id'],
                    'effective_from'       => Carbon::today()->toDateString(),
                    'rotation_anchor_date' => Carbon::today()->toDateString(),
                    'assigned_by'          => Auth::id(),
                    'is_active'            => true,
                ]);
            }
        });

        return redirect()->route('employees.index')
            ->with('success', "Datos de {$employee->first_name} actualizados correctamente.");
    }

    /**
     * Desbloquea una cuenta bloqueada por intentos fallidos de login.
     */
    public function unlock(int $id): RedirectResponse
    {
        $employee = User::findOrFail($id);
        abort_unless(Auth::user()->canManage($employee), 403);

        $employee->forceFill(['locked_until' => null, 'failed_login_attempts' => 0]);
        app(\App\Modules\Core\Services\AuditLogger::class)->saveWithAudit('auth.account_unlocked', $employee, ['name' => $employee->full_name]);

        return back()->with('success', "Se desbloqueó el acceso de {$employee->first_name}.");
    }

    /**
     * Alterna el estado activo / inactivo del colaborador.
     */
    public function toggleStatus(int $id): RedirectResponse
    {
        $tenantId = current_tenant_id_or_null();
        $employee = User::forTenant($tenantId)->findOrFail($id);
        abort_unless(Auth::user()->canManage($employee) && Auth::id() !== $employee->id, 403);

        $newStatus = $employee->status === 'active' ? 'inactive' : 'active';
        app(\App\Modules\Core\Services\AuditLogger::class)->updateWithAudit('employee.updated', $employee, ['status' => $newStatus], ['name' => $employee->full_name]);

        $statusMsg = $newStatus === 'active' ? 'activado' : 'desactivado';
        return back()->with('success', "Colaborador {$employee->first_name} ha sido {$statusMsg}.");
    }
}
