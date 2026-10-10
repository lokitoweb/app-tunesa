<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\SendTenantWebhook;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\AuditLogger;
use App\Services\Integrations\WebhookSender;
use App\Services\Push\PushException;
use App\Services\TenantContext;
use App\Support\Labels;
use App\Support\NetworkError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Botones de prueba e historial de envíos de "Marca y app › Integraciones".
 *
 * Mismo permiso que la pantalla (company.configure). La empresa sale SIEMPRE
 * del TenantContext; en la vista global del Super Admin las pruebas y el
 * historial son los de la plataforma (tenant_id NULL).
 */
class IntegrationsWebController extends Controller
{
    /** Clase del envío push (la crea el módulo de notificaciones push) */
    public const FCM_SENDER = 'App\\Services\\Push\\FcmSender';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly WebhookSender $sender,
    ) {}

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
            ? ['body' => WebhookSender::truncate($this->text($result['response'] ?? null) ?? 'Enviada a ' . $user->email . '.', WebhookSender::RESPONSE_LIMIT)]
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
        $date   = fn (?Carbon $d) => $d?->copy()->setTimezone($zone)->format('d/m/Y H:i:s');

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
            'data'        => ['message' => 'Prueba desde TuneSA'],
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
