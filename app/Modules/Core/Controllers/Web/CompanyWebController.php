<?php

namespace App\Modules\Core\Controllers\Web;

use App\Modules\Core\Controllers\Api\TenantController;
use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Models\Scopes\TenantScope;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\TenantContext;
use App\Modules\ModuleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Empresas de la plataforma (Super Admin): alta con su primer
 * Administrador de Empresa, edición, marca blanca y suspensión.
 * Mismas reglas que la API /api/superadmin/tenants (TenantController).
 * Funciona en la vista global y con una empresa elegida en el selector.
 */
class CompanyWebController extends Controller
{
    public const PLANS = [
        'trial'        => 'Prueba',
        'basic'        => 'Básico',
        'professional' => 'Profesional',
        'enterprise'   => 'Empresarial',
    ];

    public const STATUSES = [
        'active'    => 'Activa',
        'suspended' => 'Suspendida',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request): View
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,suspended,cancelled'],
        ]);

        $search = $request->input('search');

        $companies = Tenant::query()
            ->when($search, fn ($q) => $q->where(fn ($sq) => $sq
                ->where('company_name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")
                ->orWhere('ruc', 'like', "%{$search}%")))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->withCount(['users as users_count' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)->where('role', '!=', Role::SUPER_ADMIN)])
            ->orderBy('company_name')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total'          => Tenant::count(),
            'active'         => Tenant::where('status', 'active')->count(),
            'total_users'    => User::withoutGlobalScope(TenantScope::class)->where('role', '!=', Role::SUPER_ADMIN)->count(),
            'custom_domains' => Tenant::whereNotNull('custom_domain')->where('custom_domain', '!=', '')->count(),
        ];
        $plans           = self::PLANS;
        $statuses        = self::STATUSES;
        $timezones       = \DateTimeZone::listIdentifiers();
        $optionalModules = ModuleRegistry::optional();
        $moduleLabels    = ModuleRegistry::LABELS;

        return view('companies.index', compact('companies', 'stats', 'plans', 'statuses', 'timezones', 'optionalModules', 'moduleLabels'));
    }

    public function create(): View
    {
        return view('companies.form', ['company' => new Tenant([
            'plan' => 'trial', 'status' => 'active', 'timezone' => 'America/Lima',
            'primary_color' => '#3C729E', 'secondary_color' => '#F59E0B',
        ]), 'isEdit' => false]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(array_merge($this->rules(), [
            'admin_first_name' => ['required', 'string', 'max:100'],
            'admin_last_name'  => ['required', 'string', 'max:100'],
            'admin_email'      => ['required', 'email', 'unique:users,email'],
            'admin_password'   => ['required', 'string', 'min:8'],
        ]), [], $this->attributes());

        // La empresa nueva no es la elegida en el selector: su administrador
        // se crea sin el contexto de otra empresa (BelongsToTenant lo impediría).
        $this->tenantContext->clear();

        $tenant = DB::transaction(function () use ($request, $validated) {
            $tenant = Tenant::create($this->tenantData($validated));

            if ($request->hasFile('logo')) {
                $tenant->update(['logo_path' => $request->file('logo')->store("logos/{$tenant->id}", 'public')]);
            }

            User::create([
                'tenant_id'            => $tenant->id,
                'first_name'           => $validated['admin_first_name'],
                'last_name'            => $validated['admin_last_name'],
                'email'                => $validated['admin_email'],
                'password'             => $validated['admin_password'],
                'role'                 => Role::COMPANY_ADMIN,
                'status'               => 'active',
                'must_change_password' => true,
            ]);

            return $tenant;
        });

        $this->audit->log('tenant.created', $tenant, ['admin_email' => $validated['admin_email']]);

        return redirect()->route('companies.index')
            ->with('success', "Empresa {$tenant->company_name} creada. Su administrador ({$validated['admin_email']}) deberá cambiar la contraseña temporal al ingresar.");
    }

    public function edit(int $id): View|JsonResponse
    {
        $company = Tenant::findOrFail($id);
        $admins  = User::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $company->id)
            ->where('role', Role::COMPANY_ADMIN)
            ->orderBy('first_name')
            ->get();

        if (request()->wantsJson() || request()->ajax()) {
            return response()->json([
                'success' => true,
                'company' => $company,
                'admins'  => $admins->map(fn ($a) => [
                    'id'     => $a->id,
                    'name'   => $a->full_name,
                    'email'  => $a->email,
                    'status' => $a->status,
                ]),
                'logo_url' => $company->logo_path ? asset('storage/' . $company->logo_path) : null,
                'enabled_modules' => array_values(array_filter(ModuleRegistry::optional(), fn ($m) => ModuleRegistry::enabledFor($company, $m))),
            ]);
        }

        return view('companies.form', ['company' => $company, 'isEdit' => true, 'admins' => $admins]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $tenant    = Tenant::findOrFail($id);
        $validated = $request->validate($this->rules($tenant), [], $this->attributes());

        $data = $this->tenantData($validated);

        if ($request->boolean('modules_submitted')) {
            $data['settings'] = ['disabled_modules' => array_values(array_diff(ModuleRegistry::optional(), $validated['modules'] ?? []))]
                + (array) $tenant->settings;
        }

        if ($request->hasFile('logo')) {
            if ($tenant->logo_path) {
                Storage::disk('public')->delete($tenant->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store("logos/{$tenant->id}", 'public');
        } elseif ($request->boolean('remove_logo') && $tenant->logo_path) {
            Storage::disk('public')->delete($tenant->logo_path);
            $data['logo_path'] = null;
        }

        $this->audit->updateWithAudit('tenant.updated', $tenant, $data);

        return redirect()->route('companies.index')->with('success', "Empresa {$tenant->company_name} actualizada.");
    }

    /** Reglas de la API (TenantController) acotadas a lo que edita este formulario */
    private function rules(?Tenant $tenant = null): array
    {
        $api = TenantController::tenantRules($tenant);

        return [
            'company_name'    => ['required', ...array_slice($api['company_name'], 1)],
            'slug'            => ['required', ...array_slice($api['slug'], 1)],
            'email'           => ['required', ...array_slice($api['email'], 1)],
            'custom_domain'   => ['nullable', ...array_slice($api['custom_domain'], 2)],
            'ruc'             => ['nullable', 'string', 'max:20'],
            'phone'           => ['nullable', 'string', 'max:20'],
            'address'         => ['nullable', 'string', 'max:500'],
            'plan'            => ['required', 'in:' . implode(',', array_keys(self::PLANS))],
            'status'          => ['required', 'in:' . implode(',', array_keys(self::STATUSES))],
            'timezone'        => ['required', 'timezone:all'],
            'primary_color'   => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo'            => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            // Módulos activos de la empresa (ModuleRegistry). modules_submitted marca que el
            // formulario los trae: sin casillas marcadas el navegador no envía "modules".
            'modules_submitted' => ['nullable', 'boolean'],
            'modules'           => ['nullable', 'array'],
            'modules.*'         => ['string', Rule::in(ModuleRegistry::optional())],
        ];
    }

    private function tenantData(array $validated): array
    {
        $data = collect($validated)->only([
            'company_name', 'slug', 'email', 'custom_domain', 'ruc', 'phone', 'address',
            'plan', 'status', 'timezone', 'primary_color', 'secondary_color',
        ])->all();

        $data['slug'] = strtolower($data['slug']);
        if (! empty($data['custom_domain'])) {
            $data['custom_domain'] = strtolower($data['custom_domain']);
        }

        return $data;
    }

    private function attributes(): array
    {
        return [
            'company_name' => 'nombre de la empresa', 'slug' => 'subdominio', 'custom_domain' => 'dominio propio',
            'email' => 'correo de la empresa', 'timezone' => 'zona horaria', 'primary_color' => 'color primario',
            'secondary_color' => 'color secundario', 'admin_first_name' => 'nombres del administrador',
            'admin_last_name' => 'apellidos del administrador', 'admin_email' => 'correo del administrador',
            'admin_password' => 'contraseña temporal',
        ];
    }
}
