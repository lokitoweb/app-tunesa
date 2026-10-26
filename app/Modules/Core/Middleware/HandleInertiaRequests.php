<?php

namespace App\Modules\Core\Middleware;

use App\Modules\Core\Controllers\Web\NotificationWebController;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Services\TenantContext;
use App\Modules\Core\Support\Labels;
use App\Modules\Core\Support\PanelNavigation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Inertia\Middleware;

/**
 * Datos que toda pantalla Vue del panel recibe (layout: menú, marca, usuario,
 * campana, avisos). Son closures: solo se calculan en respuestas Inertia.
 * Nunca incluir secretos aquí: viajan en el HTML de cada pantalla.
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Una visita de Inertia trae X-Requested-With (axios) y los controladores que
     * responden JSON con $request->ajax() le devolvían JSON crudo ("plain JSON
     * response"). Se quita aquí, una sola vez para todo el panel.
     */
    public function handle(Request $request, \Closure $next)
    {
        if ($request->header('X-Inertia')) {
            $request->headers->remove('X-Requested-With');
        }

        return parent::handle($request, $next);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return parent::share($request) + [
            // Para los formularios POST clásicos del layout (salir, cambiar de empresa, campana)
            'csrf'  => fn () => csrf_token(),
            'flash' => fn () => array_filter([
                'success' => $request->session()->get('success'),
                'info'    => $request->session()->get('info'),
                'error'   => $request->session()->get('error'),
            ]),
            'auth' => fn () => $user ? [
                'name'           => trim("{$user->first_name} {$user->last_name}"),
                'initials'       => Str::upper(mb_substr($user->first_name ?? 'U', 0, 1) . mb_substr($user->last_name ?? '', 0, 1)),
                'email'          => $user->email,
                'role_label'     => Labels::enum('user_role', $user->role),
                'is_super_admin' => $user->isSuperAdmin(),
                // Buscador de colaboradores de la barra superior
                'can_search'     => $user->can('users.manage'),
            ] : null,
            'brand' => function () use ($user) {
                // Empresa activa (la del usuario, o la que eligió el Super Admin); sin empresa = plataforma
                $tenant   = app(TenantContext::class)->getOrNull() ?? $user?->tenant;
                $settings = TenantSetting::forTenant($tenant);
                $logo     = $settings->displayLogoUrl();
                $logoDark = $settings->logoDarkUrl();

                return [
                    'trade_name'    => $settings->tradeName(),
                    'company_name'  => $tenant->company_name ?? 'Plataforma',
                    'tenant_id'     => $tenant?->id,
                    'logo_url'      => $logo,
                    'default_logo'  => $settings->usesDefaultLogo(),
                    'logo_dark_url' => $logoDark && $logoDark !== $logo ? $logoDark : null,
                ];
            },
            'nav'     => fn () => $user ? PanelNavigation::for($user, $request) : [],
            'tenants' => fn () => $user?->isSuperAdmin() ? Tenant::orderBy('company_name')->get(['id', 'company_name', 'ruc', 'status']) : [],
            // Vista global del Super Admin: los listados muestran la empresa de cada registro
            'globalView'  => fn () => (bool) View::shared('globalView', false),
            'tenantNames' => fn () => View::shared('tenantNames', []),
            'today'       => fn () => now()->isoFormat('dddd D [de] MMMM'),
            'notifications' => fn () => $user ? [
                'unread' => $user->unreadNotifications()->count(),
                'latest' => $user->notifications()->limit(8)->get()->map(fn ($n) => [
                    'id'    => $n->id,
                    'icon'  => NotificationWebController::icon($n->data['type'] ?? null),
                    'title' => $n->data['title'] ?? 'Notificación',
                    'body'  => Str::limit($n->data['body'] ?? '', 110),
                    'ago'   => $n->created_at?->diffForHumans(),
                    'read'  => $n->read_at !== null,
                ]),
            ] : null,
        ];
    }
}
