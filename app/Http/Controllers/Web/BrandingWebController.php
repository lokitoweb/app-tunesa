<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CompanyConfiguration;
use App\Models\TenantSetting;
use App\Services\TenantContext;
use App\Services\TenantSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * "Marca y app": marca blanca, app móvil, reglas operativas e integraciones.
 *
 * La empresa editada sale SIEMPRE del TenantContext (la del usuario, o la que
 * eligió el Super Admin en el selector). Sin empresa (vista global del Super
 * Admin) se edita la fila por defecto de la plataforma (tenant_id NULL).
 */
class BrandingWebController extends Controller
{
    public const TABS = ['marca', 'app', 'reglas', 'integraciones'];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantSettingsService $settings,
    ) {}

    public function edit(Request $request): View
    {
        $tenant = $this->tenantContext->getOrNull();
        $row    = TenantSetting::rowFor($tenant) ?? new TenantSetting();

        return view('settings.branding', [
            'tenant'    => $tenant,
            'isGlobal'  => $tenant === null,
            'row'       => $row,
            'values'    => $this->settings->editablePayload($tenant),
            'effective' => TenantSetting::forTenant($tenant),
            'inherited' => TenantSetting::forTenant(null),
            'policies'  => CompanyConfiguration::GEOFENCE_POLICIES,
            'tab'       => in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'marca',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant = $this->tenantContext->getOrNull();

        // Un switch desmarcado no se envía: en el formulario ausente = false
        $request->merge(collect(TenantSettingsService::BOOLEANS)
            ->mapWithKeys(fn ($f) => [$f => $request->boolean($f)])->all());

        $validated = $request->validate($this->settings->rules(global: $tenant === null), $this->settings->messages());

        $this->settings->update($tenant, $validated, $request, Auth::user());

        $tab = in_array($request->input('tab'), self::TABS, true) ? $request->input('tab') : 'marca';

        return redirect()->route('settings.branding', ['tab' => $tab])->with('success', $tenant
            ? 'Marca y configuración de la app guardadas. La app las aplica la próxima vez que se abra.'
            : 'Valores por defecto de la plataforma guardados. Aplican a las empresas que no definieron los suyos.');
    }
}
