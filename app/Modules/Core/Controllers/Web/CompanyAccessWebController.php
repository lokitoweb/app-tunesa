<?php

namespace App\Modules\Core\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Configuración › Acceso a varias empresas (solo Super Admin): a una persona con un rol de gestión le
 * asigna otras empresas del grupo para supervisarlas con su mismo rol. Sigue siendo de su empresa (ahí
 * marca, su legajo y su app); en el panel elige la empresa en el selector de la barra superior.
 */
class CompanyAccessWebController extends Controller
{
    /** Roles que supervisan o gestionan (un colaborador sin rol de gestión no tiene panel que compartir) */
    public const ROLES = ['company_admin', 'hr_manager', 'manager', 'accountant', 'logistics', 'supervisor'];

    public function index(Request $request): View
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $companies = Tenant::where('status', 'active')->orderBy('company_name')->get(['id', 'company_name']);
        $people = User::withoutGlobalScopes()->whereNotNull('tenant_id')->whereNull('deleted_at')->where('status', 'active')
            ->whereIn('role', self::ROLES)->whereIn('tenant_id', $companies->modelKeys())
            ->with(['jobPosition:id,name', 'extraTenants:tenants.id', 'tenant:id,company_name'])
            ->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'tenant_id', 'first_name', 'last_name', 'role', 'position', 'position_id', 'email']);

        return view('settings.company-access', compact('companies', 'people'));
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate([
            'people'     => ['required', 'array', 'max:2000'],
            'people.*'   => ['integer'],
            'access'     => ['nullable', 'array'],
            'access.*'   => ['array'],
            'access.*.*' => ['integer'],
        ]);

        $active = Tenant::where('status', 'active')->pluck('company_name', 'id');
        $people = User::withoutGlobalScopes()->whereIn('id', $data['people'])->whereNotNull('tenant_id')
            ->where('status', 'active')->whereIn('role', self::ROLES)->get();
        $changed = 0;

        DB::transaction(function () use ($people, $data, $active, $request, $audit, &$changed) {
            foreach ($people as $person) {
                // Solo empresas activas distintas de la suya
                $wanted = collect($data['access'][$person->id] ?? [])->map(fn ($id) => (int) $id)
                    ->filter(fn ($id) => $active->has($id) && $id !== (int) $person->tenant_id)->unique()->values();
                $before = $person->extraTenants()->pluck('tenants.id')->map(fn ($id) => (int) $id)->sort()->values();
                if ($before->all() === $wanted->sort()->values()->all()) {
                    continue;
                }
                $person->extraTenants()->sync($wanted->mapWithKeys(fn ($id) => [$id => ['granted_by' => $request->user()->id]])->all());
                $audit->log('users.tenant_access_changed', $person, [
                    'name'   => $person->full_name,
                    'before' => $before->map(fn ($id) => $active[$id] ?? "#{$id}")->join(', ') ?: 'Solo su empresa',
                    'after'  => $wanted->map(fn ($id) => $active[$id])->join(', ') ?: 'Solo su empresa',
                ]);
                $changed++;
            }
        });

        return back()->with('success', $changed ? "Acceso actualizado para {$changed} persona(s). Rige desde su siguiente pantalla." : 'No hubo cambios.');
    }
}
