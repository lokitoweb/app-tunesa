<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CompanyConfiguration;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Push\FcmSender;
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

        if ($tenant === null) {
            // Plataforma: interruptores; uno desmarcado no se envía, así que ausente = false
            $request->merge(collect(TenantSettingsService::BOOLEANS)
                ->mapWithKeys(fn ($f) => [$f => $request->boolean($f)])->all());
        } else {
            // Empresa: selector Heredar ("" → NULL) / Sí ("1") / No ("0"); ausente = no cambiar
            $request->merge(collect(TenantSettingsService::BOOLEANS)
                ->filter(fn ($f) => $request->exists($f))
                ->mapWithKeys(fn ($f) => [$f => TenantSettingsService::triState($request->input($f))])->all());
        }

        $validated = $request->validate($this->settings->rules(global: $tenant === null), $this->settings->messages());

        $this->settings->update($tenant, $validated, $request, Auth::user());

        $tab = in_array($request->input('tab'), self::TABS, true) ? $request->input('tab') : 'marca';

        return redirect()->route('settings.branding', ['tab' => $tab])->with('success', $tenant
            ? 'Marca y configuración de la app guardadas. La app las aplica la próxima vez que se abra.'
            : 'Valores por defecto de la plataforma guardados. Aplican a las empresas que no definieron los suyos.');
    }

    /**
     * "Probar push": envía una notificación de prueba al usuario indicado (o a
     * quien la pide). En una empresa solo se buscan sus usuarios (scope del tenant).
     */
    public function pushTest(Request $request, FcmSender $fcm, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['push_email' => ['nullable', 'email', 'max:255']]);

        /** @var User $actor */
        $actor = Auth::user();
        $to    = filled($data['push_email'] ?? null)
            ? User::where('email', $data['push_email'])->first()
            : $actor;

        if (! $to) {
            return back()->withInput()->withErrors(['push_email' => 'No hay un usuario con ese correo en esta empresa.']);
        }

        $result = $fcm->test($to);

        $audit->log('push.tested', $to, [
            'ok'     => $result['ok'],
            'status' => $result['status'],
            'ms'     => $result['ms'],
            'error'  => $result['error'] ?? null,
        ]);

        $redirect = redirect()->route('settings.branding', ['tab' => 'integraciones'])->withInput();

        return $result['ok']
            ? $redirect->with('success', "Notificación de prueba enviada a {$to->email} ({$result['ms']} ms).")
            : $redirect->with('error', "No se pudo enviar la prueba a {$to->email}: " . ($result['error'] ?? 'error desconocido') . '.');
    }
}
