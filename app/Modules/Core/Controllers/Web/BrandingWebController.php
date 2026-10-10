<?php

namespace App\Modules\Core\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\CompanyConfiguration;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Services\TenantContext;
use App\Modules\Core\Services\TenantSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * "Marca y app": marca blanca, app móvil y reglas operativas.
 * (Las integraciones tienen su pantalla: Integrations\IntegrationsWebController.)
 *
 * La empresa editada sale SIEMPRE del TenantContext (la del usuario, o la que
 * eligió el Super Admin en el selector). Sin empresa (vista global del Super
 * Admin) se edita la fila por defecto de la plataforma (tenant_id NULL).
 */
class BrandingWebController extends Controller
{
    public const TABS = ['marca', 'app', 'reglas'];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantSettingsService $settings,
    ) {}

    public function edit(Request $request): View|RedirectResponse
    {
        // Enlaces antiguos a la pestaña que ahora es una pantalla propia
        if ($request->query('tab') === 'integraciones') {
            return redirect()->route('settings.integrations');
        }

        $tenant = $this->tenantContext->getOrNull();

        return view('settings.branding', [
            'tenant'    => $tenant,
            'isGlobal'  => $tenant === null,
            'row'       => TenantSetting::editableRowFor($tenant),
            'values'    => $this->settings->editablePayload($tenant),
            'effective' => TenantSetting::forTenant($tenant),
            'inherited' => TenantSetting::forTenant(null),
            'policies'  => CompanyConfiguration::GEOFENCE_POLICIES,
            // Campos nuevos (antifraude): solo se muestran si la columna existe
            'columns'   => self::settingColumns(),
            'tab'       => in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'marca',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant = $this->tenantContext->getOrNull();
        // El push (fcm_enabled) se edita en Integraciones: este formulario no lo trae y no debe apagarlo
        $this->settings->updateFromPanel($tenant, $request, Auth::user(), array_values(array_diff(TenantSettingsService::BOOLEANS, ['fcm_enabled'])));

        $tab = in_array($request->input('tab'), self::TABS, true) ? $request->input('tab') : 'marca';

        return redirect()->route('settings.branding', ['tab' => $tab])->with('success', $tenant
            ? 'Marca y configuración de la app guardadas. La app las aplica la próxima vez que se abra.'
            : 'Valores por defecto de la plataforma guardados. Aplican a las empresas que no definieron los suyos.');
    }

    /** Columnas de tenant_settings (los campos nuevos solo se muestran si existen) */
    public static function settingColumns(): array
    {
        return TenantSettingsService::columns();
    }
}
