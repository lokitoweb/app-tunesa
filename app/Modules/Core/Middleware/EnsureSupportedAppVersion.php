<?php

namespace App\Modules\Core\Middleware;

use App\Modules\Core\Models\TenantSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware: EnsureSupportedAppVersion (alias app.version)
 *
 * Si la app envía X-App-Version menor que la versión mínima de su empresa y
 * la empresa exige actualizar (force_update), responde 426 para que la app
 * muestre la pantalla de "Actualiza la app". Va después de [tenant].
 * No aplica a /api/auth/* (login, logout) ni a /api/app/config.
 */
class EnsureSupportedAppVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $version = $request->header('X-App-Version');

        if (! is_string($version) || trim($version) === '' || $request->is('api/auth/*', 'api/app/config', 'api/v1/app/config')) {
            return $next($request);
        }

        $settings = TenantSetting::forTenant(app(\App\Modules\Core\Services\TenantContext::class)->getOrNull());

        if ($settings->force_update && $settings->isBelowMinimum($version)) {
            $mobile = $settings->mobile();

            return response()->json([
                'success' => false,
                'code'    => 'APP_UPDATE_REQUIRED',
                'message' => 'Hay una nueva versión de la app. Actualízala para seguir marcando.',
                'data'    => [
                    'min_supported_version' => $mobile['min_supported_version'],
                    'latest_version'        => $mobile['latest_version'],
                ],
            ], 426);
        }

        return $next($request);
    }
}
