<?php

namespace App\Modules\Core\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal web del colaborador (Mi espacio): solo con el permiso portal.access, sin acceso al panel,
 * cuenta activa y empresa activa. Activa la empresa igual que el panel (SetWebTenant), así cada consulta queda
 * en su empresa. El Super Admin no tiene portal (no pertenece a una empresa).
 */
class SetPortalTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = $user?->tenant;

        // Quien tiene panel (o es Super Admin) trabaja en el panel, aunque su rol también incluya el portal
        if ($user && ($user->isSuperAdmin() || $user->hasPermission('dashboard.view'))) {
            return redirect()->route('dashboard');
        }

        if (! $user || ! $tenant?->isActive() || $user->status !== 'active' || ! $user->hasPermission('portal.access')) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['email' => 'Tu cuenta no tiene acceso a Mi espacio o la empresa está suspendida.']);
        }

        app(SetWebTenant::class)->activate($tenant);
        // Menú del portal: las mismas reglas que protegen cada página
        // y quién es (la cabecera lo saluda por su nombre, con su cargo y empresa)
        \Inertia\Inertia::share('portal', fn () => \App\Modules\HR\Controllers\Web\PortalController::sections($user) + ['person' => [
            'first_name' => \Illuminate\Support\Str::title(mb_strtolower((string) $user->first_name)),
            'full_name'  => \Illuminate\Support\Str::title(mb_strtolower($user->full_name)),
            'position'   => $user->position,
            'company'    => $tenant->company_name,
        ]]);

        return $next($request);
    }
}
