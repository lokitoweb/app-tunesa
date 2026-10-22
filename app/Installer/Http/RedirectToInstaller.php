<?php

namespace App\Installer\Http;

use App\Installer\InstallState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware global. Sin instalar: todo va al asistente (la API responde 503)
 * y la sesión usa archivos, porque aún no hay tablas. Instalado: /install no
 * existe (404), así nadie puede reinstalar encima de los datos.
 */
class RedirectToInstaller
{
    public function handle(Request $request, Closure $next): Response
    {
        $onInstaller = $request->is('install', 'install/*');

        if (! InstallState::enabled()) {
            abort_if($onInstaller, 404);

            return $next($request);
        }
        if ($request->is('up')) {
            return $next($request);
        }

        $installed = InstallState::installed();

        if ($installed) {
            abort_if($onInstaller, 404);

            return $next($request);
        }

        // Aún sin base de datos: sesión, caché y colas sin tablas
        config([
            'session.driver' => 'file',
            'cache.default'  => 'file',
            'queue.default'  => 'sync',
        ]);

        if ($onInstaller) {
            return $next($request);
        }

        if ($request->is('api', 'api/*')) {
            return response()->json([
                'success' => false,
                'message' => 'La plataforma aún no está instalada.',
                'code'    => 'NOT_INSTALLED',
            ], 503);
        }

        return redirect('/install');
    }
}
