<?php

namespace App\Modules\Core\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Services\AccountDeletion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Páginas públicas que piden Google Play y App Store:
 *   GET  /privacidad       Política de privacidad (enlace de la ficha y de la app)
 *   GET  /eliminar-cuenta  Cómo pedir que se elimine la cuenta y los datos
 *   POST /eliminar-cuenta  Pedido desde la web (sin iniciar sesión)
 *
 * Usan la marca y el contacto de la empresa del dominio, o los de la
 * plataforma (Configuración › Marca y app), así se administran desde el panel.
 */
class LegalController extends Controller
{
    public const DELETION_RECEIVED = 'Recibimos tu pedido. Si el correo corresponde a una cuenta, RRHH de tu empresa '
        . 'eliminará tu cuenta y tus datos en un máximo de 30 días y te avisará por correo.';

    public function privacy(Request $request): View
    {
        return view('legal.privacy', $this->brand($request));
    }

    public function deletionForm(Request $request): View
    {
        return view('legal.account-deletion', $this->brand($request));
    }

    public function deletionStore(Request $request, AccountDeletion $deletion): RedirectResponse
    {
        $data = $request->validate([
            'email'  => ['required', 'email', 'max:255'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ], [
            'email.required' => 'Escribe el correo con el que ingresas a la app.',
            'email.email'    => 'Escribe un correo válido.',
        ]);

        $deletion->request($deletion->findUser($data['email']), $data['email'], $data['reason'] ?? null, 'web');

        // Mismo mensaje exista o no la cuenta: la página no revela quién tiene cuenta
        return redirect()->route('legal.account-deletion')->with('status', self::DELETION_RECEIVED);
    }

    private function brand(Request $request): array
    {
        $tenant   = Tenant::fromHost($request->getHost());
        $settings = TenantSetting::forTenant($tenant);
        $platform = TenantSetting::forTenant(null);

        return [
            'tenant'     => $tenant,
            'appName'    => $settings->tradeName(),
            'contact'    => array_filter($settings->contact()) ?: array_filter($platform->contact()),
            'updatedAt'  => '12/10/2026',
        ];
    }
}
