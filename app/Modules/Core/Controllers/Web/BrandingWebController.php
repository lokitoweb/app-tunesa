<?php

namespace App\Modules\Core\Controllers\Web;

use App\Modules\Integrations\Controllers\Api\WebhookReceiverController;
use App\Modules\Core\Controllers\Controller;
use App\Modules\Integrations\Jobs\SendTenantWebhook;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\CompanyConfiguration;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Services\TenantContext;
use App\Modules\Core\Services\TenantSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

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
        // El secreto del webhook nunca se muestra vacío: si falta, se genera y se guarda
        $row    = TenantSetting::ensureWebhookSecret($tenant);
        $values = $this->settings->editablePayload($tenant);

        return view('settings.branding', [
            'tenant'    => $tenant,
            'isGlobal'  => $tenant === null,
            'row'       => $row,
            'values'    => $values,
            'effective' => TenantSetting::forTenant($tenant),
            'inherited' => TenantSetting::forTenant(null),
            'policies'  => CompanyConfiguration::GEOFENCE_POLICIES,
            // Campos nuevos (geocodificación, push, antifraude): solo se muestran si la columna existe
            'columns'   => self::settingColumns(),
            // Clave de Google efectiva: la propia o la heredada de la plataforma
            'hasMapsKey' => ! empty($values['has_maps_api_key']) || filled(self::secret(TenantSetting::rowFor(null), 'maps_api_key')),
            'fcm'       => self::fcmStatus(),
            'geoProbe'  => $this->geoProbe($tenant),
            'webhookSecret' => $row->readableWebhookSecret(),
            // Receptor de la plataforma: URL precargada mientras la empresa no ponga la suya
            'receiverUrls'  => [
                'webhook_url'           => WebhookReceiverController::urlFor($tenant, SendTenantWebhook::TARGET_WEBHOOK),
                'notification_endpoint' => WebhookReceiverController::urlFor($tenant, SendTenantWebhook::TARGET_NOTIFICATION),
            ],
            'tab'       => in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'marca',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tenant   = $this->tenantContext->getOrNull();
        $booleans = self::booleanFields();

        if ($tenant === null) {
            // Plataforma: interruptores; uno desmarcado no se envía, así que ausente = false
            $request->merge(collect($booleans)
                ->mapWithKeys(fn ($f) => [$f => $request->boolean($f)])->all());
        } else {
            // Empresa: selector Heredar ("" → NULL) / Sí ("1") / No ("0"); ausente = no cambiar
            $request->merge(collect($booleans)
                ->filter(fn ($f) => $request->exists($f))
                ->mapWithKeys(fn ($f) => [$f => TenantSettingsService::triState($request->input($f))])->all());
        }

        $fcmJson = $this->fcmServiceAccountInput($request, $tenant);

        $validated = $request->validate($this->settings->rules(global: $tenant === null), $this->settings->messages());

        $this->settings->update($tenant, $validated, $request, Auth::user());
        $this->saveFcmServiceAccount($request, $tenant, $fcmJson);

        $tab = in_array($request->input('tab'), self::TABS, true) ? $request->input('tab') : 'marca';

        return redirect()->route('settings.branding', ['tab' => $tab])->with('success', $tenant
            ? 'Marca y configuración de la app guardadas. La app las aplica la próxima vez que se abra.'
            : 'Valores por defecto de la plataforma guardados. Aplican a las empresas que no definieron los suyos.');
    }

    /** Columnas de tenant_settings (los campos nuevos solo se muestran si existen) */
    public static function settingColumns(): array
    {
        static $columns = null;

        return $columns ??= Schema::getColumnListing('tenant_settings');
    }

    /** Opciones Sí/No: las del servicio más fcm_enabled si la columna existe */
    private static function booleanFields(): array
    {
        $extra = in_array('fcm_enabled', self::settingColumns(), true) ? ['fcm_enabled'] : [];

        return array_values(array_unique(array_merge(TenantSettingsService::BOOLEANS, $extra)));
    }

    /**
     * Cuenta de servicio de Firebase (solo la fila de la plataforma): pegada en
     * el textarea o subida como archivo .json. Valida que sea un JSON con
     * project_id, client_email y private_key. Devuelve el JSON normalizado,
     * o null si no se envió nada.
     */
    private function fcmServiceAccountInput(Request $request, ?Tenant $tenant): ?string
    {
        if ($tenant !== null || ! in_array('fcm_service_account_json', self::settingColumns(), true)) {
            // Una empresa nunca la cambia: se ignora lo que llegue
            $request->request->remove('fcm_service_account_json');
            $request->files->remove('fcm_service_account_file');

            return null;
        }

        $file = $request->file('fcm_service_account_file');
        $raw  = $file instanceof UploadedFile && $file->isValid() && $file->getSize() <= 64 * 1024
            ? (string) file_get_contents($file->getRealPath())
            : trim((string) $request->input('fcm_service_account_json'));

        $request->request->remove('fcm_service_account_json');

        if ($file instanceof UploadedFile && $raw === '') {
            throw ValidationException::withMessages(['fcm_service_account_json' => 'No se pudo leer el archivo (máximo 64 KB).']);
        }
        if ($raw === '') {
            return null;
        }

        $data    = json_decode($raw, true);
        $missing = is_array($data)
            ? array_values(array_filter(['project_id', 'client_email', 'private_key'], fn ($k) => blank($data[$k] ?? null)))
            : null;

        if ($missing === null || $missing) {
            throw ValidationException::withMessages(['fcm_service_account_json' => $missing === null
                ? 'La cuenta de servicio debe ser el archivo JSON que descargas de Firebase (Configuración del proyecto › Cuentas de servicio).'
                : 'Al JSON de la cuenta de servicio le falta: ' . implode(', ', $missing) . '.']);
        }
        if (! str_contains($data['private_key'], 'PRIVATE KEY')) {
            throw ValidationException::withMessages(['fcm_service_account_json' => 'El campo private_key no parece una clave privada válida.']);
        }

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Guarda (o borra) la cuenta de servicio en la fila de la plataforma */
    private function saveFcmServiceAccount(Request $request, ?Tenant $tenant, ?string $json): void
    {
        if ($tenant !== null || ! in_array('fcm_service_account_json', self::settingColumns(), true)) {
            return;
        }

        $clear = $request->boolean('clear_fcm_service_account_json');
        if ($json === null && ! $clear) {
            return;
        }

        $row = TenantSetting::platform();
        $row->fcm_service_account_json = $clear && $json === null ? null : $json;
        $row->updated_by = Auth::id();
        $row->save();
    }

    /** Estado de la cuenta de servicio de Firebase (sin exponer la clave) */
    public static function fcmStatus(): array
    {
        if (! in_array('fcm_service_account_json', self::settingColumns(), true)) {
            return ['available' => false, 'configured' => false, 'project_id' => null, 'client_email' => null];
        }

        $data = json_decode((string) self::secret(TenantSetting::rowFor(null), 'fcm_service_account_json'), true);

        return [
            'available'    => true,
            'configured'   => is_array($data) && filled($data['private_key'] ?? null),
            'project_id'   => $data['project_id'] ?? null,
            'client_email' => $data['client_email'] ?? null,
        ];
    }

    /** Lee un campo cifrado; null si no existe o no se puede descifrar (APP_KEY cambiada) */
    private static function secret(?TenantSetting $row, string $field): ?string
    {
        try {
            return $row?->{$field};
        } catch (Throwable) {
            return null;
        }
    }

    /** Coordenadas para "Probar geocodificación": la primera sucursal, o el centro de Lima */
    private function geoProbe(?Tenant $tenant): array
    {
        $branch = $tenant
            ? Branch::forTenant($tenant->id)->whereNotNull('latitude')->whereNotNull('longitude')->orderBy('id')->first()
            : null;

        return $branch
            ? ['lat' => (float) $branch->latitude, 'lng' => (float) $branch->longitude, 'label' => $branch->name]
            : ['lat' => -12.046374, 'lng' => -77.042793, 'label' => 'Centro de Lima'];
    }
}
