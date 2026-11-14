<?php

namespace App\Modules\HR\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Position;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\HR\Services\EmployeeTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Colaboradores › Trasladar a otra empresa del grupo (permiso employees.transfer: RR. HH. y Super Admin;
 * el Super Admin puede darlo a otros roles). La lógica está en EmployeeTransferService.
 */
class EmployeeTransferWebController extends Controller
{
    /** Roles que se pueden dar en la empresa de destino (nunca Super Admin; administrador solo si quien traslada puede) */
    private const ROLES = ['company_admin', 'hr_manager', 'manager', 'accountant', 'logistics', 'supervisor', 'employee'];

    /** GET: sedes y cargos activos de la empresa de destino, para el diálogo */
    public function options(Request $request, int $id): JsonResponse
    {
        $this->employee($id);
        $tenantId = (int) $request->validate(['tenant_id' => ['required', 'integer']])['tenant_id'];
        $tenant = Tenant::where('status', 'active')->findOrFail($tenantId);

        return response()->json([
            'branches'  => Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('is_active', true)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'positions' => Position::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, int $id, EmployeeTransferService $transfers): RedirectResponse
    {
        $employee = $this->employee($id);
        $data = $request->validate([
            'tenant_id'           => ['required', 'integer', Rule::exists('tenants', 'id')->where('status', 'active'), Rule::notIn([$employee->tenant_id])],
            'effective_date'      => ['required', 'date', 'before_or_equal:today'],
            'branch_id'           => ['required', 'integer', Rule::exists('branches', 'id')->where('tenant_id', $request->integer('tenant_id'))->where('is_active', true)->whereNull('deleted_at')],
            'role'                => ['required', Rule::in(self::ROLES)],
            'position_id'         => ['nullable', 'integer', Rule::exists('positions', 'id')->where('tenant_id', $request->integer('tenant_id'))],
            'base_salary'         => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'copy_documents'      => ['boolean'],
            'onboarding_required' => ['boolean'],
            'notes'               => ['nullable', 'string', 'max:500'],
        ], [
            'tenant_id.not_in'               => 'Elige una empresa distinta a la actual.',
            'effective_date.before_or_equal' => 'Registra el traslado desde su primer día en la nueva empresa (hoy o antes).',
        ], ['tenant_id' => 'la empresa de destino', 'effective_date' => 'la fecha de ingreso', 'branch_id' => 'la sede', 'role' => 'el rol', 'position_id' => 'el cargo', 'base_salary' => 'la remuneración']);
        abort_unless($request->user()->canAssignRole($data['role']), 403, 'No puedes dar un rol igual o superior al tuyo.');

        $transfer = $transfers->transfer($employee, $data + ['copy_documents' => $request->boolean('copy_documents'), 'onboarding_required' => $request->boolean('onboarding_required')], $request->user());
        $transfer->load('toTenant:id,company_name');

        return redirect()->route('employees.edit', $employee->id)->with('success',
            "{$employee->full_name} fue trasladado a {$transfer->toTenant->company_name} desde el {$transfer->effective_date->format('d/m/Y')}. "
            . 'Aquí queda su historia con su cese; puedes calcular su liquidación. Entra con su mismo correo y contraseña.');
    }

    /** El colaborador, dentro de lo que ve quien traslada (su empresa; el Super Admin, todas) */
    private function employee(int $id): User
    {
        return User::forTenant(current_tenant_id_or_null())->whereNotNull('tenant_id')->findOrFail($id);
    }
}
