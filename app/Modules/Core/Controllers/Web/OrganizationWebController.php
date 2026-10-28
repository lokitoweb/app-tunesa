<?php

namespace App\Modules\Core\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Department;
use App\Modules\Core\Models\Position;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\Core\Models\Zone;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\OrgScope;
use App\Modules\Core\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Jerarquía organizacional: Zonas → Sedes → Áreas → Cargos, con la dotación de
 * cada cruce. Las sedes se crean y ubican en el mapa en /branches; aquí se
 * agrupan en zonas, se mantienen los catálogos de zonas, áreas y cargos, y se
 * arma la plantilla de cada sede (cargos y plazas previstas frente a las reales).
 *
 * Un usuario con alcance de zona o sede ve solo su territorio (BranchScope) y
 * no puede cambiar la estructura.
 */
class OrganizationWebController extends Controller
{
    /** Catálogos editables: [modelo, prefijo de auditoría, terminación del participio] */
    private const CATALOGS = [
        'zones'       => [Zone::class, 'zone', 'a'],
        'departments' => [Department::class, 'department', 'a'],
        'positions'   => [Position::class, 'position', 'o'],
    ];

    public function __construct(private readonly OrgScope $scope, private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $tenantId = current_tenant_id_or_null();

        $branches = Branch::with(['supervisor:id,first_name,last_name', 'positions:id'])
            ->withCount(['employees' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('name')->get();

        // Dotación activa por sede, área y puesto (alimenta el filtro en cascada)
        $staff = DB::table('employee_branch')
            ->join('users', 'users.id', '=', 'employee_branch.user_id')
            ->whereIn('employee_branch.branch_id', $branches->modelKeys())
            ->where('users.status', 'active')->whereNull('users.deleted_at')
            ->groupBy('employee_branch.branch_id', 'users.department_id', 'users.position_id', 'users.position')
            ->get(['employee_branch.branch_id', 'users.department_id', 'users.position_id', 'users.position', DB::raw('count(*) as total')])
            ->groupBy('branch_id');

        $supervisors = User::forTenant($tenantId)
            ->whereIn('role', ['company_admin', 'hr_manager', 'supervisor'])
            ->where('status', 'active')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->map(fn ($u) => [
                'id'    => $u->id,
                'name'  => trim("{$u->first_name} {$u->last_name}"),
                'email' => $u->email,
            ]);

        $defaultRadius = (int) (TenantSetting::forTenant(app(TenantContext::class)->getOrNull())->default_geofence_radius ?? 100);

        return Inertia::render('Core/Pages/Organization/Index', [
            'zones' => Zone::orderBy('name')->get(['id', 'tenant_id', 'name', 'code', 'is_active']),
            'departments' => Department::withCount(['users' => fn ($q) => $q->where('status', 'active')])
                ->with('head:id,first_name,last_name')
                ->orderBy('name')->get(['id', 'tenant_id', 'name', 'code', 'head_id', 'is_active'])
                ->map(fn (Department $d) => $d->only(['id', 'tenant_id', 'name', 'code', 'head_id', 'is_active', 'users_count']) + ['head' => $d->head?->full_name]),
            'positions' => Position::withCount(['users' => fn ($q) => $q->where('status', 'active')])
                ->orderBy('name')->get(['id', 'tenant_id', 'department_id', 'name', 'code', 'description', 'is_roaming', 'is_active', 'required_documents']),
            // Documentos que un cargo puede pedir además de los de toda la empresa (Configuración de la app)
            'documentTypes'    => \App\Modules\HR\Models\EmployeeDocument::TYPES,
            'companyDocuments' => \App\Modules\Core\Models\CompanyConfiguration::current()->requiredDocuments(),
            'branches' => $branches->map(fn (Branch $b) => [
                'id'            => $b->id,
                'tenant_id'     => $b->tenant_id,
                'zone_id'       => $b->zone_id,
                'name'          => $b->name,
                'code'          => $b->code,
                'type'          => $b->branch_type,
                'city'          => $b->city,
                'address'       => $b->address,
                'latitude'      => (float) $b->latitude,
                'longitude'     => (float) $b->longitude,
                'radius'        => $b->allowed_radius_meters,
                'is_active'     => $b->is_active,
                'supervisor_id' => $b->supervisor_id,
                'supervisor'    => $b->supervisor?->full_name,
                'employees'     => $b->employees_count,
                'staff'         => ($staff[$b->id] ?? collect())->map(fn ($row) => [
                    'department_id' => $row->department_id,
                    'position_id'   => $row->position_id,
                    'position'      => $row->position,
                    'total'         => (int) $row->total,
                ])->values(),
                // Plantilla: plazas previstas por cargo
                'plan'          => $b->positions->map(fn (Position $p) => [
                    'position_id' => $p->id,
                    'planned'     => (int) $p->pivot->planned_headcount,
                ])->values(),
            ]),
            'supervisors'          => $supervisors,
            'defaultRadius'        => $defaultRadius,
            'branchTypes'          => Branch::TYPES,
            'suggestedDepartments' => Department::SUGGESTED,
            'suggestedPositions'   => array_column(Position::SUGGESTED, 0),
            // La estructura la cambia quien tiene alcance de toda la empresa
            'canManage'            => ! $this->scope->restricted(),
            'scopeLabel'           => $request->user()->isOrgScoped() ? $request->user()->scopeLabel() : null,
        ]);
    }

    /** POST /organization/positions/suggested → agrega cargos habituales con su área, si existe */
    public function storeSuggestedPositions(Request $request): RedirectResponse
    {
        abort_if($this->scope->restricted(), 403, 'Solo un usuario con alcance de toda la empresa cambia la estructura.');
        $catalog = collect(Position::SUGGESTED)->keyBy(0);
        $data = $request->validate([
            'names'   => ['required', 'array', 'min:1', 'max:' . $catalog->count()],
            'names.*' => ['string', Rule::in($catalog->keys()->all())],
        ]);

        $existing    = Position::pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
        $departments = Department::pluck('id', 'name');
        $created     = 0;
        foreach (array_unique($data['names']) as $name) {
            if (in_array(mb_strtolower($name), $existing, true)) {
                continue;
            }
            [, $department, $roaming] = $catalog[$name];
            $row = Position::create(['name' => $name, 'department_id' => $departments[$department] ?? null, 'is_roaming' => $roaming, 'is_active' => true]);
            $this->audit->logCreated('position.created', $row, ['name' => $row->name]);
            $created++;
        }

        return back()->with('success', $created === 1 ? '1 cargo agregado.' : "{$created} cargos agregados.");
    }

    /**
     * PUT /organization/branches/{id}/positions → plantilla de la sede: qué cargos
     * tiene y cuántas plazas de cada uno. Reemplaza la plantilla completa.
     */
    public function updateBranchPositions(Request $request, int $id): RedirectResponse
    {
        abort_if($this->scope->restricted(), 403, 'Solo un usuario con alcance de toda la empresa cambia la estructura.');
        $branch = Branch::findOrFail($id);

        $data = $request->validate([
            'positions'                     => ['present', 'array', 'max:200'],
            'positions.*.position_id'       => ['required', 'integer', 'distinct', Rule::exists('positions', 'id')->where('tenant_id', $branch->tenant_id)],
            'positions.*.planned_headcount' => ['required', 'integer', 'min:0', 'max:9999'],
        ], ['positions.*.position_id.distinct' => 'Ese cargo ya está en la plantilla.']);

        $before = $branch->positions()->pluck('planned_headcount', 'positions.id')->all();
        $sync   = collect($data['positions'])->mapWithKeys(fn ($row) => [
            $row['position_id'] => ['planned_headcount' => $row['planned_headcount'], 'tenant_id' => $branch->tenant_id],
        ])->all();
        $branch->positions()->sync($sync);

        $after = array_map(fn ($row) => $row['planned_headcount'], $sync);
        if ($before != $after) {
            $this->audit->log('branch.staffing_updated', $branch, [
                'name'      => $branch->name,
                'positions' => count($after),
                'planned'   => array_sum($after),
            ]);
        }

        return back()->with('success', "Plantilla de {$branch->name} guardada.");
    }

    /** POST /organization/{catalog} → nueva zona, área o cargo */
    public function store(Request $request, string $catalog): RedirectResponse
    {
        [$model, $prefix, $g] = $this->catalog($catalog);
        $row = $model::create($this->validated($request, $catalog));
        $this->audit->logCreated("{$prefix}.created", $row, ['name' => $row->name]);

        return back()->with('success', "«{$row->name}» cread{$g}.");
    }

    /** PUT /organization/{catalog}/{id} */
    public function update(Request $request, string $catalog, int $id): RedirectResponse
    {
        [$model, $prefix, $g] = $this->catalog($catalog);
        $row = $model::findOrFail($id);
        $this->audit->updateWithAudit("{$prefix}.updated", $row, $this->validated($request, $catalog, $row), ['name' => $row->name]);

        return back()->with('success', "«{$row->name}» actualizad{$g}.");
    }

    /** DELETE /organization/{catalog}/{id} → sus sedes o colaboradores quedan sin zona / área / cargo (conservan el nombre) */
    public function destroy(string $catalog, int $id): RedirectResponse
    {
        [$model, $prefix, $g] = $this->catalog($catalog);
        $row = $model::findOrFail($id);
        $row->delete();
        $this->audit->log("{$prefix}.deleted", $row, ['name' => $row->name]);

        return back()->with('success', "«{$row->name}» eliminad{$g}.");
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

    /** @return array{0: class-string<Model>, 1: string, 2: string} */
    private function catalog(string $catalog): array
    {
        abort_if($this->scope->restricted(), 403, 'Solo un usuario con alcance de toda la empresa cambia la estructura.');

        return self::CATALOGS[$catalog];
    }

    private function validated(Request $request, string $catalog, ?Model $row = null): array
    {
        $tenantId = current_tenant_id();
        $unique   = fn (string $column) => Rule::unique($catalog, $column)->where('tenant_id', $tenantId)->ignore($row?->id);

        $rules = [
            'name'      => ['required', 'string', $catalog === 'positions' ? 'max:100' : 'max:120', $unique('name')],
            'code'      => ['nullable', 'string', 'max:30', $unique('code')],
            'is_active' => ['required', 'boolean'],
        ];
        if ($catalog === 'departments') {
            $rules['head_id'] = ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)->where('status', 'active')];
        }
        if ($catalog === 'positions') {
            $rules += [
                'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('tenant_id', $tenantId)],
                'description'   => ['nullable', 'string', 'max:255'],
                'is_roaming'    => ['required', 'boolean'],
                // Además de los de toda la empresa (p. ej. licencia para Conductor)
                'required_documents'   => ['nullable', 'array'],
                'required_documents.*' => ['string', Rule::in(array_keys(\App\Modules\HR\Models\EmployeeDocument::TYPES))],
            ];
        }

        return $request->validate($rules, ['name.unique' => 'Ya existe con ese nombre.', 'code.unique' => 'Ese código ya está en uso.']);
    }
}
