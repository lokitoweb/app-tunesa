<?php

namespace App\Modules\HR\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Services\TenantContext;
use App\Modules\HR\Models\Shift;
use App\Modules\Core\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ShiftWebController (Web)
 *
 * Turnos fijos y rotativos, tolerancia de tardanza y asignación de turnos
 * a colaboradores. Las consultas quedan filtradas por empresa vía TenantScope.
 */
class ShiftWebController extends Controller
{
    public function index(Request $request): Response
    {
        $shifts = Shift::withCount(['employeeShifts as employees_count' => fn ($q) => $q->current()])
            ->orderBy('start_time')
            ->get();

        $assignments = EmployeeShift::current()
            ->with(['user:id,first_name,last_name,employee_code', 'shift:id,name,color,type', 'branch:id,name'])
            ->when($request->shift_id, fn ($q) => $q->where('shift_id', $request->shift_id))
            ->orderByDesc('effective_from')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (EmployeeShift $a) => [
                'id'        => $a->id,
                'tenant_id' => $a->tenant_id,
                'employee'  => $a->user?->full_name,
                'shift'     => $a->shift?->name,
                'color'     => $a->shift?->color,
                'branch'    => $a->branch?->name,
                'from'      => $a->effective_from?->format('d/m/Y'),
                'until'     => $a->effective_until?->format('d/m/Y'),
            ]);

        return Inertia::render('HR/Pages/Shifts/Index', [
            'shifts'      => $shifts->map(fn (Shift $s) => self::present($s) + ['employees_count' => $s->employees_count]),
            'assignments' => $assignments,
            // Con sus sedes vigentes: el modal filtra por la sucursal elegida (antes listaba a todos
            // sin filtrar y la búsqueda por DNI nunca encontraba porque no se enviaba el documento)
            'employees'   => User::whereIn('role', User::CLOCKING_ROLES)->where('status', 'active')
                ->with(['branches' => fn ($q) => $q->select('branches.id')
                    ->where(fn ($w) => $w->whereNull('employee_branch.assigned_until')->orWhere('employee_branch.assigned_until', '>=', now(tenant_timezone())->toDateString()))])
                ->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'employee_code', 'document_number'])
                ->map(fn (User $u) => $u->only(['id', 'first_name', 'last_name', 'employee_code', 'document_number']) + ['branch_ids' => $u->branches->pluck('id')->all()]),
            'branches'    => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'today'       => now()->toDateString(),
            'canAssign'   => $request->user()->can('shifts.assign'),
        ]);
    }

    public function create(): Response
    {
        // Tolerancia y refrigerio por defecto de "Marca y app › Reglas operativas"
        $rules = TenantSetting::forTenant(app(TenantContext::class)->getOrNull());

        return Inertia::render('HR/Pages/Shifts/Form', ['shift' => self::present(new Shift([
            'type' => 'fixed', 'grace_period_minutes' => $rules->default_grace_minutes, 'working_days' => [1, 2, 3, 4, 5],
            'color' => '#3B82F6', 'is_active' => true, 'break_allowed' => true,
            'break_duration_minutes' => $rules->lunch_minutes, 'daily_hours' => 8,
            'start_time' => '08:00', 'end_time' => '17:00',
        ]))]);
    }

    /** Turno tal como lo consume el panel (listado y formulario) */
    private static function present(Shift $shift): array
    {
        return [
            'id'                     => $shift->id,
            'tenant_id'              => $shift->tenant_id,
            'name'                   => $shift->name,
            'code'                   => $shift->code,
            'type'                   => $shift->type,
            'start_time'             => substr((string) $shift->start_time, 0, 5),
            'end_time'               => substr((string) $shift->end_time, 0, 5),
            'crosses_midnight'       => (bool) $shift->crosses_midnight,
            'grace_period_minutes'   => $shift->grace_period_minutes,
            'daily_hours'            => $shift->daily_hours,
            'break_allowed'          => (bool) $shift->break_allowed,
            'break_duration_minutes' => $shift->break_duration_minutes,
            'working_days'           => $shift->working_days ?? [],
            'rotation_work_days'     => $shift->rotation_work_days,
            'rotation_rest_days'     => $shift->rotation_rest_days,
            'color'                  => $shift->color,
            'description'            => $shift->description,
            'is_active'              => (bool) $shift->is_active,
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $shift = Shift::create($this->validated($request));
        app(\App\Modules\Core\Services\AuditLogger::class)->logCreated('shift.created', $shift, ['name' => $shift->name]);

        return redirect()->route('shifts.index')->with('success', 'Turno creado correctamente.');
    }

    public function edit(int $id): Response
    {
        return Inertia::render('HR/Pages/Shifts/Form', ['shift' => self::present(Shift::findOrFail($id))]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $shift = Shift::findOrFail($id);
        app(\App\Modules\Core\Services\AuditLogger::class)->updateWithAudit('shift.updated', $shift, $this->validated($request, $shift), ['name' => $shift->name]);

        return redirect()->route('shifts.index')->with('success', "Turno {$shift->name} actualizado.");
    }

    public function destroy(int $id): RedirectResponse
    {
        $shift = Shift::findOrFail($id);

        if ($shift->employeeShifts()->current()->exists()) {
            return back()->with('error', 'El turno tiene colaboradores asignados. Reasígnalos antes de eliminarlo.');
        }

        $shift->delete();
        app(\App\Modules\Core\Services\AuditLogger::class)->log('shift.deleted', $shift, ['name' => $shift->name]);

        return back()->with('success', 'Turno eliminado.');
    }

    /** Asignar un turno a uno o varios colaboradores */
    public function assign(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'shift_id'             => ['required', 'integer', Rule::exists('shifts', 'id')->where('tenant_id', current_tenant_id())],
            'branch_id'            => ['required', 'integer', Rule::exists('branches', 'id')->where('tenant_id', current_tenant_id())],
            'user_ids'             => ['required', 'array', 'min:1'],
            'user_ids.*'           => ['integer', Rule::exists('users', 'id')->where('tenant_id', current_tenant_id())],
            'effective_from'       => ['required', 'date'],
            'effective_until'      => ['nullable', 'date', 'after_or_equal:effective_from'],
            'rotation_anchor_date' => ['nullable', 'date'],
            'replace_current'      => ['nullable', 'boolean'],
        ]);

        foreach ($validated['user_ids'] as $userId) {
            if ($request->boolean('replace_current', true)) {
                // Cierra la asignación vigente el día anterior al nuevo turno
                EmployeeShift::where('user_id', $userId)->current()
                    ->update([
                        'effective_until' => now()->parse($validated['effective_from'])->subDay()->toDateString(),
                        'is_active'       => false,
                    ]);
            }

            EmployeeShift::create([
                'user_id'              => $userId,
                'shift_id'             => $validated['shift_id'],
                'branch_id'            => $validated['branch_id'],
                'effective_from'       => $validated['effective_from'],
                'effective_until'      => $validated['effective_until'] ?? null,
                'rotation_anchor_date' => $validated['rotation_anchor_date'] ?? $validated['effective_from'],
                'assigned_by'          => Auth::id(),
                'is_active'            => true,
            ]);
        }

        return back()->with('success', 'Turno asignado a ' . count($validated['user_ids']) . ' colaborador(es).');
    }

    public function unassign(int $id): RedirectResponse
    {
        EmployeeShift::findOrFail($id)->update([
            'effective_until' => now()->toDateString(),
            'is_active'       => false,
        ]);

        return back()->with('success', 'Asignación finalizada.');
    }

    private function validated(Request $request, ?Shift $shift = null): array
    {
        $rotating = $request->input('type') === 'rotating';

        $data = $request->validate([
            'name'                   => ['required', 'string', 'max:100'],
            'code'                   => ['nullable', 'string', 'max:50',
                Rule::unique('shifts', 'code')->where('tenant_id', current_tenant_id())->whereNull('deleted_at')->ignore($shift?->id)],
            'type'                   => ['required', 'in:fixed,rotating'],
            'start_time'             => ['required', 'date_format:H:i'],
            'end_time'               => ['required', 'date_format:H:i'],
            'grace_period_minutes'   => ['required', 'integer', 'between:0,240'],
            'break_allowed'          => ['nullable', 'boolean'],
            'break_duration_minutes' => ['required', 'integer', 'between:0,480'],
            'working_days'           => [$rotating ? 'nullable' : 'required', 'array'],
            'working_days.*'         => ['integer', 'between:0,6'],
            'rotation_work_days'     => [$rotating ? 'required' : 'nullable', 'integer', 'between:1,60'],
            'rotation_rest_days'     => [$rotating ? 'required' : 'nullable', 'integer', 'between:1,60'],
            'daily_hours'            => ['required', 'numeric', 'between:0.5,24'],
            'color'                  => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active'              => ['nullable', 'boolean'],
            'description'            => ['nullable', 'string', 'max:1000'],
        ]);

        $data['break_allowed']    = $request->boolean('break_allowed');
        $data['is_active']        = $request->boolean('is_active');
        $data['crosses_midnight'] = $data['end_time'] <= $data['start_time'];
        $data['working_days']     = array_map('intval', $data['working_days'] ?? [0, 1, 2, 3, 4, 5, 6]);

        if (! $rotating) {
            $data['rotation_work_days'] = null;
            $data['rotation_rest_days'] = null;
        }

        return $data;
    }
}
