<?php

namespace App\Modules\Core\Controllers\Api;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Support\PrivacyPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/legal/privacy — la política de privacidad para mostrarla dentro de la app (pública: también
 * antes del login). Con sesión, la de la empresa del colaborador; sin sesión, la del dominio o la
 * de la plataforma. Es el mismo texto que la página /privacidad (Marca y app › Políticas de la app).
 */
class LegalController extends Controller
{
    public function privacy(Request $request): JsonResponse
    {
        $tenant = $request->user('sanctum')?->tenant ?? Tenant::fromHost($request->getHost());
        $settings = TenantSetting::forTenant($tenant);
        $policy = new PrivacyPolicy($settings);
        $contact = array_filter($settings->contact()) ?: array_filter(TenantSetting::forTenant(null)->contact());

        return response()->json([
            'success' => true,
            'data'    => [
                'title'      => 'Política de privacidad',
                'app_name'   => $settings->tradeName(),
                'updated_on' => $policy->updatedOn()->toDateString(),
                'blocks'     => $policy->blocks(),
                'contact'    => $contact ?: null,
                'url'        => route('legal.privacy'),
            ],
        ]);
    }
}
