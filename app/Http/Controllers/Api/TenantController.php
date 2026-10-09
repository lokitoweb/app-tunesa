<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * TenantController (Super Admin)
 *
 * Alta y administración de empresas de la plataforma. Estas rutas viven fuera
 * del middleware [tenant], así que los global scopes no filtran: el super
 * admin ve todas las empresas.
 *
 *   /api/superadmin/tenants            CRUD
 *   /api/superadmin/tenants/{id}/suspend
 *   /api/superadmin/tenants/{id}/activate
 *
 * Además, para cada empresa (con [tenant]):
 *   GET /api/tenant/branding            Marca blanca (público, para la app antes del login)
 *   PUT /api/admin/branding             Editar marca blanca (Company Admin)
 */
class TenantController extends Controller
{
    /** Listar empresas */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'in:active,suspended,cancelled'],
            'plan'   => ['sometimes', 'in:trial,basic,professional,enterprise'],
        ]);

        $tenants = Tenant::query()
            ->when($request->search, fn ($q) => $q->where(fn ($sq) =>
                $sq->where('company_name', 'like', "%{$request->search}%")
                   ->orWhere('slug', 'like', "%{$request->search}%")
                   ->orWhere('ruc', 'like', "%{$request->search}%")
            ))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->plan, fn ($q) => $q->where('plan', $request->plan))
            ->withCount([
                'users as employees_count' => fn ($q) => $q->where('role', '!=', Role::SUPER_ADMIN),
                'branches',
            ])
            ->orderBy('company_name')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $tenants->getCollection()->map(fn (Tenant $t) => $this->formatTenant($t)),
            'meta'    => [
                'total'        => $tenants->total(),
                'current_page' => $tenants->currentPage(),
                'last_page'    => $tenants->lastPage(),
            ],
        ]);
    }

    /** Ver una empresa */
    public function show(int $id): JsonResponse
    {
        $tenant = Tenant::withCount(['users as employees_count', 'branches'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $this->formatTenant($tenant, detailed: true),
        ]);
    }

    /**
     * Crear empresa + su Company Admin inicial en una sola transacción.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(array_merge(self::tenantRules(), [
            'admin'            => ['required', 'array'],
            'admin.first_name' => ['required', 'string', 'max:100'],
            'admin.last_name'  => ['required', 'string', 'max:100'],
            'admin.email'      => ['required', 'email', 'unique:users,email'],
            'admin.password'   => ['required', 'string', 'min:8'],
        ]));

        [$tenant, $admin] = DB::transaction(function () use ($validated) {
            $tenant = Tenant::create(collect($validated)->except('admin')->all());

            $admin = User::create([
                'tenant_id'  => $tenant->id,
                'first_name' => $validated['admin']['first_name'],
                'last_name'  => $validated['admin']['last_name'],
                'email'      => $validated['admin']['email'],
                'password'   => $validated['admin']['password'],
                'role'       => Role::COMPANY_ADMIN,
                'status'     => 'active',
            ]);

            return [$tenant, $admin];
        });

        return response()->json([
            'success' => true,
            'message' => "Empresa {$tenant->company_name} creada.",
            'data'    => array_merge($this->formatTenant($tenant->fresh(), detailed: true), [
                'admin' => ['id' => $admin->id, 'email' => $admin->email],
            ]),
        ], 201);
    }

    /** Actualizar empresa (datos, plan, marca blanca) */
    public function update(Request $request, int $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->update($request->validate(self::tenantRules($tenant)));

        return response()->json([
            'success' => true,
            'message' => 'Empresa actualizada.',
            'data'    => $this->formatTenant($tenant->fresh(), detailed: true),
        ]);
    }

    /** Dar de baja una empresa (soft delete + estado cancelado) */
    public function destroy(int $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->update(['status' => 'cancelled']);
        $tenant->delete();

        return response()->json([
            'success' => true,
            'message' => 'Empresa dada de baja.',
        ]);
    }

    /** Suspender empresa: sus usuarios dejan de poder usar la API (ResolveTenant → 403) */
    public function suspend(int $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->update(['status' => 'suspended']);

        return response()->json([
            'success' => true,
            'message' => 'Empresa suspendida.',
            'data'    => $this->formatTenant($tenant),
        ]);
    }

    /** Reactivar empresa */
    public function activate(int $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->update(['status' => 'active']);

        return response()->json([
            'success' => true,
            'message' => 'Empresa activada.',
            'data'    => $this->formatTenant($tenant),
        ]);
    }

    // =========================================================================
    // MARCA BLANCA (dentro de la empresa)
    // =========================================================================

    /**
     * Marca blanca de la empresa resuelta. Público: la app la pide antes del
     * login para pintar logo y colores.
     * GET /api/tenant/branding
     */
    public function branding(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => current_tenant()->branding(),
        ]);
    }

    /**
     * Editar marca blanca de la empresa propia (Company Admin).
     * PUT /api/admin/branding  (multipart si incluye logo)
     */
    public function updateBranding(Request $request): JsonResponse
    {
        $tenant = current_tenant();

        $validated = $request->validate([
            'company_name'    => ['sometimes', 'string', 'max:200'],
            'primary_color'   => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo'            => ['sometimes', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
        ]);

        if ($request->hasFile('logo')) {
            $validated['logo_path'] = $request->file('logo')->store("logos/{$tenant->id}", 'public');
            unset($validated['logo']);
        }

        $tenant->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Marca actualizada.',
            'data'    => $tenant->fresh()->branding(),
        ]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    public static function tenantRules(?Tenant $tenant = null): array
    {
        $required = $tenant ? 'sometimes' : 'required';

        return [
            'slug'                 => [$required, 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn(['www', 'api', 'admin', 'app', 'mail', 'cdn']),
                Rule::unique('tenants', 'slug')->ignore($tenant?->id)],
            'custom_domain'        => ['sometimes', 'nullable', 'string', 'max:255',
                'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/',
                Rule::unique('tenants', 'custom_domain')->ignore($tenant?->id)],
            'company_name'         => [$required, 'string', 'max:200'],
            'ruc'                  => ['sometimes', 'nullable', 'string', 'max:20'],
            'email'                => [$required, 'email', Rule::unique('tenants', 'email')->ignore($tenant?->id)],
            'phone'                => ['sometimes', 'nullable', 'string', 'max:20'],
            'address'              => ['sometimes', 'nullable', 'string', 'max:500'],
            'primary_color'        => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color'      => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'plan'                 => ['sometimes', 'in:trial,basic,professional,enterprise'],
            'trial_ends_at'        => ['sometimes', 'nullable', 'date'],
            'subscription_ends_at' => ['sometimes', 'nullable', 'date'],
            'status'               => ['sometimes', 'in:active,suspended,cancelled'],
            'timezone'             => ['sometimes', 'timezone:all'],
            'currency'             => ['sometimes', 'string', 'size:3'],
            'settings'             => ['sometimes', 'nullable', 'array'],
        ];
    }

    private function formatTenant(Tenant $tenant, bool $detailed = false): array
    {
        $data = [
            'id'              => $tenant->id,
            'slug'            => $tenant->slug,
            'company_name'    => $tenant->company_name,
            'plan'            => $tenant->plan,
            'status'          => $tenant->status,
            'employees_count' => $tenant->employees_count ?? null,
            'branches_count'  => $tenant->branches_count ?? null,
            'created_at'      => $tenant->created_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data += [
                'ruc'                  => $tenant->ruc,
                'email'                => $tenant->email,
                'phone'                => $tenant->phone,
                'address'              => $tenant->address,
                'timezone'             => $tenant->timezone,
                'currency'             => $tenant->currency,
                'trial_ends_at'        => $tenant->trial_ends_at?->toIso8601String(),
                'subscription_ends_at' => $tenant->subscription_ends_at?->toIso8601String(),
                'settings'             => $tenant->settings,
                'branding'             => $tenant->branding(),
            ];
        }

        return $data;
    }
}
