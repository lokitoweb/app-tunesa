<?php

namespace App\Modules\HR\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\HR\Models\LaborRegime;
use App\Modules\HR\Models\PensionRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** RR. HH. › Regímenes laborales: cada empresa ajusta los parámetros de sus beneficios y agrega los suyos */
class LaborRegimeWebController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        $tenantId = $this->tenantId();
        $used = EmployeeProfile::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNotNull('labor_regime')
            ->selectRaw('labor_regime, count(*) as n')->groupBy('labor_regime')->pluck('n', 'labor_regime');

        $settings = TenantSetting::forTenant(\App\Modules\Core\Models\Tenant::find($tenantId));

        return Inertia::render('HR/Pages/LaborRegimes/Index', [
            'regimes' => LaborRegime::catalog($tenantId)->map(fn (LaborRegime $r) => $r->only(['id', 'code', 'name', ...LaborRegime::PARAMS, 'is_active'])
                + ['people' => (int) ($used[$r->code] ?? 0)])->values(),
            'companyVacationDays' => (int) $settings->vacation_days_per_year,
            'parameters' => ['minimum_wage' => (float) $settings->minimum_wage, 'family_allowance_pct' => (float) $settings->family_allowance_pct],
            'pensions' => PensionRate::catalog($tenantId)->map->only(['id', 'name', 'system', 'contribution_pct', 'insurance_pct', 'commission_pct', 'is_active'])->values(),
        ]);
    }

    /** Remuneración mínima y % de asignación familiar de la empresa */
    public function updateParameters(Request $request): RedirectResponse
    {
        $tenant = \App\Modules\Core\Models\Tenant::findOrFail($this->tenantId());
        $data = $request->validate([
            'minimum_wage'         => ['required', 'numeric', 'min:0', 'max:999999'],
            'family_allowance_pct' => ['required', 'numeric', 'min:0', 'max:100'],
        ], [], ['minimum_wage' => 'la remuneración mínima', 'family_allowance_pct' => 'el % de asignación familiar']);
        $this->audit->updateWithAudit('labor_regime.parameters_updated', TenantSetting::editableRowFor($tenant), $data + ['updated_by' => $request->user()->id]);

        return back()->with('success', 'Parámetros guardados.');
    }

    public function updatePension(Request $request, int $id): RedirectResponse
    {
        $rate = PensionRate::withoutGlobalScopes()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $pct = ['required', 'numeric', 'min:0', 'max:50'];
        $data = $request->validate(['contribution_pct' => $pct, 'insurance_pct' => $pct, 'commission_pct' => $pct, 'is_active' => ['boolean']],
            [], ['contribution_pct' => 'el aporte', 'insurance_pct' => 'el seguro', 'commission_pct' => 'la comisión']);
        $this->audit->updateWithAudit('pension_rate.updated', $rate, $data, ['name' => $rate->name]);

        return back()->with('success', "Tasas de {$rate->name} guardadas.");
    }

    public function store(Request $request): RedirectResponse
    {
        $tenantId = $this->tenantId();
        $data = $this->validated($request, $tenantId);
        $code = Str::slug($data['name'], '_') ?: 'regimen';
        for ($n = 2, $base = $code; LaborRegime::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('code', $code)->exists(); $n++) {
            $code = "{$base}_{$n}";
        }
        $regime = LaborRegime::create($data + ['tenant_id' => $tenantId, 'code' => Str::limit($code, 50, ''), 'is_active' => true]);
        $this->audit->log('labor_regime.created', $regime, ['name' => $regime->name]);

        return back()->with('success', "Régimen «{$regime->name}» agregado.");
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $tenantId = $this->tenantId();
        $regime = LaborRegime::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($id);
        $data = $this->validated($request, $tenantId, $regime->id) + ['is_active' => $request->boolean('is_active', true)];
        $this->audit->updateWithAudit('labor_regime.updated', $regime, $data, ['name' => $data['name']]);

        return back()->with('success', "Régimen «{$regime->name}» guardado. Rige para los próximos cálculos.");
    }

    private function validated(Request $request, int $tenantId, ?int $ignore = null): array
    {
        $number = fn (int $max) => ['nullable', 'numeric', 'min:0', "max:{$max}"];

        return $request->validate([
            'name'                    => ['required', 'string', 'max:100', Rule::unique('labor_regimes', 'name')->where('tenant_id', $tenantId)->ignore($ignore)],
            'vacation_days'           => ['nullable', 'integer', 'min:0', 'max:60'],
            'gratifications_per_year' => $number(4),
            'gratification_bonus_pct' => $number(100),
            'cts_per_year'            => $number(4),
            'indemnity_days_per_year' => $number(90),
            'indemnity_cap_days'      => ['nullable', 'integer', 'min:0', 'max:720'],
        ], [], [
            'name' => 'el nombre', 'vacation_days' => 'los días de vacaciones', 'gratifications_per_year' => 'las gratificaciones',
            'gratification_bonus_pct' => 'la bonificación', 'cts_per_year' => 'la CTS', 'indemnity_days_per_year' => 'la indemnización', 'indemnity_cap_days' => 'el tope',
        ]);
    }

    private function tenantId(): int
    {
        $id = current_tenant_id_or_null();
        abort_unless($id, 422, 'Elige una empresa.');

        return (int) $id;
    }
}
