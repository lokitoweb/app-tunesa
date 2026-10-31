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

        $tenant   = app(\App\Modules\Core\Services\TenantContext::class)->getOrNull();
        $settings = TenantSetting::forTenant($tenant);
        // Va antes de auth:sanctum: el token se verifica aquí con el guard de Sanctum
        if (auth('sanctum')->user()) {
            self::rememberNewerVersion($version, $settings, $tenant);
        }

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

    /**
     * La versión más reciente se aprende sola: la primera vez que un usuario entra con una
     * app más nueva que `latest_version`, se guarda (plataforma y, si la tiene propia, su
     * empresa) y las apps más viejas ven el aviso de actualizar. Solo versiones X.Y.Z.
     * ponytail: confía en el X-App-Version de un usuario con sesión; si alguien lo falsea, el
     * administrador corrige la versión en Marca y app.
     */
    public static function rememberNewerVersion(string $version, TenantSetting $settings, ?\App\Modules\Core\Models\Tenant $tenant): void
    {
        $version = TenantSetting::normalizeVersion($version);
        if (! preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,6}$/', $version)
            || version_compare($version, TenantSetting::normalizeVersion((string) ($settings->latest_version ?: '0.0.0')), '<=')) {
            return;
        }

        foreach (array_filter([TenantSetting::editableRowFor(null), $tenant ? TenantSetting::rowFor($tenant) : null]) as $row) {
            if ($row->tenant_id && blank($row->latest_version)) {
                continue; // la empresa hereda la de la plataforma
            }
            if (version_compare($version, TenantSetting::normalizeVersion((string) ($row->latest_version ?: '0.0.0')), '>')) {
                $row->forceFill(['latest_version' => $version])->save();
            }
        }
        \Illuminate\Support\Facades\Log::info("App: versión más reciente detectada {$version}");
    }
}
