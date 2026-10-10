<?php

namespace App\Modules\Core\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Department;
use App\Modules\Core\Models\Zone;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\OrgScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Jerarquía organizacional: Zonas → Sedes → Áreas, con la dotación de cada
 * cruce. Las sedes se crean y ubican en el mapa en /branches; aquí se agrupan
 * en zonas y se mantiene el catálogo de zonas y áreas.
 *
 * Un usuario con alcance de zona o sede ve solo su territorio (BranchScope) y
 * no puede cambiar la estructura.
 */
class OrganizationWebController extends Controller
{
    /** Catálogos editables: [modelo, prefijo de auditoría] */
    private const CATALOGS = [
        'zones'       => [Zone::class, 'zone'],
        'departments' => [Department::class, 'department'],
    ];

    public function __construct(private readonly OrgScope $scope, private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $branches = Branch::with('supervisor:id,first_name,last_name')
            ->withCount(['employees' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('name')->get();

        // Dotación activa por sede, área y puesto (alimenta el filtro en cascada)
        $staff = DB::table('employee_branch')
            ->join('users', 'users.id', '=', 'employee_branch.user_id')
            ->whereIn('employee_branch.branch_id', $branches->modelKeys())
            ->where('users.status', 'active')->whereNull('users.deleted_at')
            ->groupBy('employee_branch.branch_id', 'users.department_id', 'users.position')
            ->get(['employee_branch.branch_id', 'users.department_id', 'users.position', DB::raw('count(*) as total')])
            ->groupBy('branch_id');

        return Inertia::render('Core/Pages/Organization/Index', [
            'zones' => Zone::orderBy('name')->get(['id', 'tenant_id', 'name', 'code', 'is_active']),
            'departments' => Department::withCount(['users' => fn ($q) => $q->where('status', 'active')])
                ->orderBy('name')->get(['id', 'tenant_id', 'name', 'code', 'is_active']),
            'branches' => $branches->map(fn (Branch $b) => [
                'id'         => $b->id,
                'tenant_id'  => $b->tenant_id,
                'zone_id'    => $b->zone_id,
                'name'       => $b->name,
                'code'       => $b->code,
                'type'       => $b->branch_type,
                'city'       => $b->city,
                'address'    => $b->address,
                'radius'     => $b->allowed_radius_meters,
                'is_active'  => $b->is_active,
                'supervisor' => $b->supervisor?->full_name,
                'employees'  => $b->employees_count,
                'staff'      => ($staff[$b->id] ?? collect())->map(fn ($row) => [
                    'department_id' => $row->department_id,
                    'position'      => $row->position,
                    'total'         => (int) $row->total,
                ])->values(),
            ]),
            'branchTypes'          => Branch::TYPES,
            'suggestedDepartments' => Department::SUGGESTED,
            // La estructura la cambia quien tiene alcance de toda la empresa
            'canManage'            => ! $this->scope->restricted(),
            'scopeLabel'           => $request->user()->isOrgScoped() ? $request->user()->scopeLabel() : null,
        ]);
    }

    /** POST /organization/{catalog} → nueva zona o área */
    public function store(Request $request, string $catalog): RedirectResponse
    {
        [$model, $prefix] = $this->catalog($catalog);
        $row = $model::create($this->validated($request, $catalog));
        $this->audit->logCreated("{$prefix}.created", $row, ['name' => $row->name]);

        return back()->with('success', "«{$row->name}» creada.");
    }

    /** PUT /organization/{catalog}/{id} */
    public function update(Request $request, string $catalog, int $id): RedirectResponse
    {
        [$model, $prefix] = $this->catalog($catalog);
        $row = $model::findOrFail($id);
        $this->audit->updateWithAudit("{$prefix}.updated", $row, $this->validated($request, $catalog, $row), ['name' => $row->name]);

        return back()->with('success', "«{$row->name}» actualizada.");
    }

    /** DELETE /organization/{catalog}/{id} → sus sedes o colaboradores quedan sin zona / área */
    public function destroy(string $catalog, int $id): RedirectResponse
    {
        [$model, $prefix] = $this->catalog($catalog);
        $row = $model::findOrFail($id);
        $row->delete();
        $this->audit->log("{$prefix}.deleted", $row, ['name' => $row->name]);

        return back()->with('success', "«{$row->name}» eliminada.");
    }

    /** PATCH /organization/branches/{id} → mover la sede de zona o cambiar su tipo */
    public function updateBranch(Request $request, int $id): RedirectResponse
    {
        abort_if($this->scope->restricted(), 403, 'Solo un usuario con alcance de toda la empresa cambia la estructura.');
        $branch = Branch::findOrFail($id);

        $data = $request->validate([
            'zone_id'     => ['nullable', 'integer', Rule::exists('zones', 'id')->where('tenant_id', $branch->tenant_id)],
            'branch_type' => ['required', Rule::in(array_keys(Branch::TYPES))],
        ]);
        $this->audit->updateWithAudit('branch.updated', $branch, $data, ['name' => $branch->name]);

        return back()->with('success', "Sede {$branch->name} actualizada.");
    }

    /** @return array{0: class-string<Model>, 1: string} */
    private function catalog(string $catalog): array
    {
        abort_if($this->scope->restricted(), 403, 'Solo un usuario con alcance de toda la empresa cambia la estructura.');

        return self::CATALOGS[$catalog];
    }

    private function validated(Request $request, string $catalog, ?Model $row = null): array
    {
        $unique = fn (string $column) => Rule::unique($catalog, $column)->where('tenant_id', current_tenant_id())->ignore($row?->id);

        return $request->validate([
            'name'      => ['required', 'string', 'max:120', $unique('name')],
            'code'      => ['nullable', 'string', 'max:30', $unique('code')],
            'is_active' => ['required', 'boolean'],
        ], ['name.unique' => 'Ya existe con ese nombre.', 'code.unique' => 'Ese código ya está en uso.']);
    }
}
