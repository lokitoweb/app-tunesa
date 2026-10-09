<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\EmployeeShift;
use App\Models\TenantSetting;
use App\Services\TenantContext;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ShiftWebController (Web)
 *
 * Turnos fijos y rotativos, tolerancia de tardanza y asignación de turnos
 * a colaboradores. Las consultas quedan filtradas por empresa vía TenantScope.
 */
class ShiftWebController extends Controller
{
    public function index(Request $request): View
    {
        $shifts = Shift::withCount(['employeeShifts as employees_count' => fn ($q) => $q->current()])
            ->orderBy('start_time')
            ->get();

        $assignments = EmployeeShift::current()
            ->with(['user:id,first_name,last_name,employee_code', 'shift:id,name,color,type', 'branch:id,name'])
            ->when($request->shift_id, fn ($q) => $q->where('shift_id', $request->shift_id))
            ->orderByDesc('effective_from')
            ->paginate(25)
            ->withQueryString();

        $employees = User::whereIn('role', ['employee', 'supervisor'])->where('status', 'active')
            ->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'employee_code']);
        $branches = Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return view('shifts.index', compact('shifts', 'assignments', 'employees', 'branches'));
    }

    public function create(): View
    {
        // Tolerancia y refrigerio por defecto de "Marca y app › Reglas operativas"
        $rules = TenantSetting::forTenant(app(TenantContext::class)->getOrNull());

        return view('shifts.form', ['shift' => new Shift([
            'type' => 'fixed', 'grace_period_minutes' => $rules->default_grace_minutes, 'working_days' => [1, 2, 3, 4, 5],
            'color' => '#3B82F6', 'is_active' => true, 'break_allowed' => true,
            'break_duration_minutes' => $rules->lunch_minutes, 'daily_hours' => 8,
        ])]);
    }

    public function store(Request $request): RedirectResponse
    {
        Shift::create($this->validated($request));

        return redirect()->route('shifts.index')->with('success', 'Turno creado correctamente.');
    }

    public function edit(int $id): View
    {
        return view('shifts.form', ['shift' => Shift::findOrFail($id)]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $shift = Shift::findOrFail($id);
        $shift->update($this->validated($request, $shift));

        return redirect()->route('shifts.index')->with('success', "Turno {$shift->name} actualizado.");
    }

    public function destroy(int $id): RedirectResponse
    {
        $shift = Shift::findOrFail($id);

        if ($shift->employeeShifts()->current()->exists()) {
            return back()->with('error', 'El turno tiene colaboradores asignados. Reasígnalos antes de eliminarlo.');
        }

        $shift->delete();

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
