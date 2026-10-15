<?php

namespace App\Modules\Integrations\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Support\PlatformUrl;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Services\TenantSettingsService;
use App\Modules\Integrations\Controllers\Api\WebhookReceiverController;
use App\Modules\Integrations\Jobs\SendTenantWebhook;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\Integrations\Models\WebhookDelivery;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Integrations\Services\WebhookSender;
use App\Modules\Integrations\Services\Push\PushException;
use App\Modules\Core\Services\TenantContext;
use App\Modules\Core\Support\Labels;
use App\Modules\Core\Support\NetworkError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Pantalla de Integraciones (Vue): webhook, geocodificación y push, con sus
 * botones de prueba y el historial de envíos.
 *
 * Mismo permiso que la pantalla (company.configure). La empresa sale SIEMPRE
 * del TenantContext; en la vista global del Super Admin las pruebas y el
 * historial son los de la plataforma (tenant_id NULL).
 */
class IntegrationsWebController extends Controller
{
    /** Clase del envío push (la crea el módulo de notificaciones push) */
    public const FCM_SENDER = 'App\\Modules\\Integrations\\Services\\Push\\FcmSender';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly WebhookSender $sender,
    ) {}

    /** Campos de "Marca y app" que edita esta pantalla (el resto no se toca) */
    private const FIELDS = [
        'webhook_url', 'webhook_secret', 'notification_endpoint', 'geocoding_provider', 'maps_api_key', 'clear_maps_api_key',
        'fcm_enabled', 'fcm_service_account_json', 'clear_fcm_service_account_json',
    ];

    /** GET /settings/integrations → webhook, geocodificación, push e historial de envíos */
    public function index(Request $request, TenantSettingsService $settings): Response
    {
        $tenant = $this->tenantContext->getOrNull();
        // El secreto del webhook nunca se muestra vacío: si falta, se genera y se guarda
        $row       = TenantSetting::ensureWebhookSecret($tenant);
        $values    = $settings->editablePayload($tenant);
        $inherited = TenantSetting::forTenant(null);
        $columns   = TenantSettingsService::columns();
        $isGlobal  = $tenant === null;
        // Receptor de la plataforma: URL precargada mientras la empresa no ponga la suya
        $receivers = [
            'webhook_url'           => WebhookReceiverController::urlFor($tenant, SendTenantWebhook::TARGET_WEBHOOK),
            'notification_endpoint' => WebhookReceiverController::urlFor($tenant, SendTenantWebhook::TARGET_NOTIFICATION),
        ];
        $provider = $inherited->geocoding_provider ?: 'nominatim';
        $hasFcm   = in_array('fcm_enabled', $columns, true);

        return Inertia::render('Integrations/Pages/Settings/Index', [
            'isGlobal'  => $isGlobal,
            'ownerName' => $tenant ? rtrim($tenant->company_name, '.') : null,
            // El secreto va una sola vez, precargado en su campo: es de quien administra la empresa
            'values'    => [
                // El receptor propio guardado con un dominio viejo se muestra con el actual
                'webhook_url'           => $values['webhook_url'] ? PlatformUrl::webhookTarget($values['webhook_url']) : $receivers['webhook_url'],
                'webhook_secret'        => $row->readableWebhookSecret(),
                'notification_endpoint' => $values['notification_endpoint'] ? PlatformUrl::webhookTarget($values['notification_endpoint']) : $receivers['notification_endpoint'],
                'geocoding_provider'    => (string) ($row->geocoding_provider ?? ($isGlobal ? $provider : '')),
                // Plataforma: interruptor. Empresa: '' (heredar) / '1' / '0'
                'fcm_enabled'           => ! $hasFcm ? null : ($isGlobal ? (bool) $inherited->fcm_enabled : ($row->fcm_enabled === null ? '' : ($row->fcm_enabled ? '1' : '0'))),
            ],
            'receivers' => $receivers,
            'inherited' => ['geocoding_provider' => $provider, 'fcm_enabled' => $hasFcm && (bool) $inherited->fcm_enabled],
            'has'       => [
                'geocoding_provider' => in_array('geocoding_provider', $columns, true),
                'fcm_enabled'        => $hasFcm,
                'fcm_account'        => in_array('fcm_service_account_json', $columns, true),
            ],
            'maps' => [
                'mask' => $values['maps_api_key'] ?? null,
                'own'  => (bool) $values['has_maps_api_key'],
                // Clave de Google efectiva: la propia o la heredada de la plataforma
                'any'  => ! empty($values['has_maps_api_key']) || filled(self::secret(TenantSetting::rowFor(null), 'maps_api_key')),
            ],
            // El proyecto de Firebase es de la plataforma: una empresa solo sabe si está configurado
            'fcm'       => $isGlobal ? self::fcmStatus() : ['configured' => self::fcmStatus()['configured']],
            'geoProbe'  => $this->geoProbe($tenant),
            'userEmail' => $request->user()->email,
            'updatedAt' => $row->updated_at?->setTimezone(tenant_timezone())->format('d/m/Y h:i a'),
        ]);
    }

    /** PUT /settings/integrations → guarda solo los campos de esta pantalla */
    public function update(Request $request, TenantSettingsService $settings): RedirectResponse
    {
        $tenant = $this->tenantContext->getOrNull();
        $request->replace($request->only(self::FIELDS));
        $settings->updateFromPanel($tenant, $request, $request->user(), ['fcm_enabled']);

        return redirect()->route('settings.integrations')->with('success', $tenant
            ? 'Integraciones guardadas.'
            : 'Integraciones de la plataforma guardadas. Aplican a las empresas que no definieron las suyas.');
    }

    /** Estado de la cuenta de servicio de Firebase (sin exponer la clave) */
    public static function fcmStatus(): array
    {
        if (! in_array('fcm_service_account_json', TenantSettingsService::columns(), true)) {
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

    /** POST /settings/branding/test-webhook → evento "ping" firmado, síncrono */
    public function testWebhook(Request $request): JsonResponse
    {
        return $this->test($request, SendTenantWebhook::TARGET_WEBHOOK, 'ping', 'Escribe la URL del webhook para probarla.');
    }

    /** POST /settings/branding/test-notification → evento "notification.test" al endpoint de notificaciones */
    public function testNotification(Request $request): JsonResponse
    {
        return $this->test($request, SendTenantWebhook::TARGET_NOTIFICATION, 'notification.test', 'Escribe la URL del endpoint de notificaciones para probarlo.');
    }

    /**
     * POST /settings/branding/test-push → push de prueba al teléfono del usuario
     * indicado por correo (o al propio si viene vacío). En una empresa solo se
     * encuentran sus usuarios (scope del tenant). Cada prueba queda en auditoría.
     */
    public function testPush(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['email' => ['nullable', 'email', 'max:255']], [
            'email.email' => 'Escribe un correo válido.',
        ]);

        /** @var User $user */
        $user = filled($data['email'] ?? null)
            ? User::where('email', $data['email'])->first()
            : Auth::user();

        if (! $user) {
            return response()->json(['ok' => false, 'status' => null, 'ms' => 0,
                'error' => 'No hay un usuario con ese correo en esta empresa.']);
        }
        if (! class_exists(self::FCM_SENDER)) {
            return response()->json(['ok' => false, 'status' => null, 'ms' => 0,
                'error' => 'El envío de notificaciones push no está disponible en este servidor.']);
        }
        if (blank($user->fcm_token)) {
            return response()->json(['ok' => false, 'status' => null, 'ms' => 0, 'no_device' => true,
                'error' => ($user->is(Auth::user()) ? 'Tu usuario' : "{$user->email}") . ' no tiene un dispositivo registrado. Inicia sesión en la app con este usuario para registrar el dispositivo y vuelve a probar.']);
        }

        try {
            $result = (array) app(self::FCM_SENDER)->test($user);
        } catch (Throwable $e) {
            Log::warning('Falló el push de prueba: ' . $e->getMessage(), ['user_id' => $user->id]);
            $detail = $e instanceof PushException ? $e->getMessage() : NetworkError::humanize($e->getMessage());
            $result = ['ok' => false, 'status' => null, 'ms' => 0, 'error' => 'No se pudo enviar el push: '
                . ($detail !== $e->getMessage() || $e instanceof PushException ? Str::limit($detail, 300) : 'ocurrió un error inesperado. Revisa la credencial de Firebase y vuelve a intentarlo.')];
        }

        $audit->log('push.tested', $user, array_filter([
            'sent'        => (bool) ($result['ok'] ?? false),
            'http_status' => $result['status'] ?? null,
            'response_ms' => (int) ($result['ms'] ?? 0),
            'error'       => $result['error'] ?? null,
        ], fn ($v) => $v !== null));

        return response()->json([
            'ok'     => (bool) ($result['ok'] ?? false),
            'status' => $result['status'] ?? null,
            'ms'     => (int) ($result['ms'] ?? 0),
        ] + (! empty($result['ok'])
            ? ['body' => WebhookSender::truncate($this->pushAccepted($user, $result['response'] ?? null), WebhookSender::RESPONSE_LIMIT)]
            : ['error' => WebhookSender::truncate($this->text($result['error'] ?? $result['response'] ?? null) ?? 'Error desconocido.', WebhookSender::RESPONSE_LIMIT)]));
    }

    /**
     * POST /settings/branding/regenerate-webhook-secret → secreto nuevo para la
     * empresa (o la plataforma en la vista global). Queda en auditoría sin el valor.
     */
    public function regenerateWebhookSecret(AuditLogger $audit): JsonResponse
    {
        $tenant = $this->tenantContext->getOrNull();
        $secret = TenantSetting::regenerateWebhookSecret($tenant);

        $audit->log('integration.webhook_secret_regenerated', TenantSetting::rowFor($tenant), [
            'scope' => $tenant ? 'tenant' : 'platform',
        ]);

        return response()->json([
            'success' => true,
            'secret'  => $secret,
            'message' => 'Secreto regenerado. Actualízalo en tu sistema: las firmas con el anterior ya no son válidas.',
        ]);
    }

    /** La respuesta de FCM llega como arreglo: se muestra como JSON legible */
    /**
     * HTTP 200 de FCM solo dice que Google aceptó el aviso, no que el celular lo
     * mostró: se explica qué celular lo tiene y qué revisar si no aparece.
     */
    private function pushAccepted(User $user, mixed $response): string
    {
        $zone       = $this->tenantContext->getOrNull()?->timezone ?? config('app.timezone');
        $registered = $user->fcm_token_updated_at
            ? 'Celular registrado el ' . $user->fcm_token_updated_at->copy()->setTimezone($zone)->format('d/m/Y h:i a')
                . ' (' . $user->fcm_token_updated_at->diffForHumans() . ').'
            : 'Celular registrado antes de esta versión (sin fecha): pide que abra la app para actualizar el registro.';

        $lines = [
            "Google aceptó el aviso para {$user->email}.",
            $registered,
            'Si no aparece: en el celular abre la app > Perfil > Notificaciones. Ahí se ve si el teléfono bloquea los avisos y se puede enviar una prueba al propio teléfono.',
        ];
        if (is_string($response)) {
            $response = json_decode($response, true);
        }
        if (is_string($name = is_array($response) ? ($response['name'] ?? null) : null)) {
            $lines[] = "Id de Firebase: {$name}";
        }

        return implode("\n", $lines);
    }

    private function text(mixed $value): ?string
    {
        return match (true) {
            $value === null || $value === '' => null,
            is_array($value)                 => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default                          => (string) $value,
        };
    }

    /** GET /settings/branding/deliveries → últimos 20 envíos de la empresa (o de la plataforma) */
    public function deliveries(): JsonResponse
    {
        $tenant = $this->tenantContext->getOrNull();
        $zone   = $tenant?->timezone ?? config('app.timezone');
        $date   = fn (?Carbon $d) => $d?->copy()->setTimezone($zone)->format('d/m/Y h:i:s a');

        $rows = WebhookDelivery::ofTenant($tenant?->id)->latest('id')->limit(20)->get()
            ->map(fn (WebhookDelivery $d) => [
                'id'            => $d->id,
                'event'         => $d->event,
                'event_label'   => Labels::enum('webhook_event', $d->event),
                'target'        => $d->target,
                'url'           => $d->url,
                'host'          => $d->host(),
                'attempt'       => $d->attempt,
                'status'        => $d->status,
                'http_status'   => $d->http_status,
                'response_ms'   => $d->response_ms,
                'response_body' => $d->response_body,
                'error'         => NetworkError::humanize($d->error),
                'created_at'    => $date($d->created_at),
                'updated_at'    => $date($d->updated_at),
                'next_retry_at' => $date($d->next_retry_at),
            ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * Envía de inmediato (sin cola) a la URL escrita en el formulario o, si
     * viene vacía, a la guardada. El secreto: el escrito, o el guardado.
     */
    private function test(Request $request, string $target, string $event, string $missingUrl): JsonResponse
    {
        $request->validate([
            'url'    => ['nullable', 'string', 'url:http,https', 'max:255'],
            'secret' => ['nullable', 'string', 'max:255'],
        ], ['url.url' => 'La URL debe empezar con http:// o https://.']);

        $tenant = $this->tenantContext->getOrNull();
        $row    = TenantSetting::rowFor($tenant);
        $url    = $request->input('url') ?: $row?->{$target};
        $secret = $request->input('secret') ?: $row?->webhook_secret;

        if (blank($url)) {
            return response()->json(['ok' => false, 'status' => null, 'ms' => 0, 'error' => $missingUrl], 422);
        }

        $body = json_encode([
            'event'       => $event,
            'occurred_at' => Carbon::now($tenant?->timezone ?? config('app.timezone'))->toIso8601String(),
            'tenant'      => ['slug' => $tenant?->slug],
            'data'        => ['message' => 'Prueba desde ' . config('app.name')],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $delivery = $this->sender->open($tenant?->id, $target, $event, $url, $body);
        $result   = $this->sender->send($url, $event, $body, $secret, $delivery, timeout: (int) config('integrations.test_timeout', 5));

        return response()->json([
            'ok'          => $result['ok'],
            'status'      => $result['status'],
            'ms'          => $result['ms'],
            'signed'      => $result['signature'] !== null,
            'delivery_id' => $delivery?->id,
        ] + ($result['ok'] ? ['body' => $result['body']] : ['error' => $result['error'], 'body' => $result['body'] ?? null]));
    }
}
