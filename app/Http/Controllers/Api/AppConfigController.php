<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\TenantSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración de arranque de la app móvil (marca blanca + reglas).
 *
 *   GET  /api/v1/app/config?tenant_slug=acme   Público (también /api/app/config
 *                                              y cabecera X-Tenant-Slug)
 *   GET  /api/admin/app-config                 Company Admin: su empresa
 *   PUT  /api/admin/app-config                 Company Admin (multipart; POST también)
 */
class AppConfigController extends Controller
{
    public function __construct(private readonly TenantSettingsService $settings) {}

    /** Público: sin empresa devuelve los valores de la plataforma (tenant: null) */
    public function show(Request $request): JsonResponse
    {
        $slug = $request->query('tenant_slug') ?: $request->header('X-Tenant-Slug');
        $tenant = null;

        if (filled($slug)) {
            $tenant = is_string($slug) ? Tenant::where('slug', $slug)->first() : null;

            if (! $tenant || ! $tenant->isActive()) {
                return response()->json([
                    'success' => false,
                    'code'    => 'TENANT_NOT_FOUND',
                    'message' => 'No encontramos una empresa activa con ese código.',
                ], 404);
            }
        }

        $effective = TenantSetting::forTenant($tenant);

        return response()->json([
            'success' => true,
            'data'    => $effective->toAppConfig(),
            'meta'    => ['config_version' => $effective->configVersion()],
        ]);
    }

    /** GET /api/admin/app-config */
    public function adminShow(): JsonResponse
    {
        $tenant    = current_tenant();
        $effective = TenantSetting::forTenant($tenant);

        return response()->json([
            'success' => true,
            'data'    => [
                'settings'  => $this->settings->editablePayload($tenant),
                'effective' => $effective->toAppConfig(),
            ],
            'meta'    => ['config_version' => $effective->configVersion()],
        ]);
    }

    /** PUT /api/admin/app-config — la empresa es siempre la de la request, nunca del input */
    public function adminUpdate(Request $request): JsonResponse
    {
        $tenant    = current_tenant();
        $validated = $request->validate($this->settings->rules(global: false), $this->settings->messages());

        $this->settings->update($tenant, $validated, $request, $request->user());

        return $this->adminShow();
    }
}
