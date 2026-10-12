<?php

namespace App\Modules\Integrations\Services\Push;

use App\Modules\Core\Models\Scopes\TenantScope;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\NetworkError;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envío de push por Firebase Cloud Messaging (API HTTP v1).
 *
 * Credenciales: la cuenta de servicio de Firebase guardada (cifrada) en la
 * fila global de "Marca y app" (`fcm_service_account_json`): la plataforma es
 * dueña de la única app móvil. Cada empresa decide si usa push (`fcm_enabled`,
 * NULL = heredar de la plataforma).
 *
 * Autenticación OAuth 2.0 de cuenta de servicio: JWT RS256 firmado con
 * openssl_sign (iss = client_email, scope firebase.messaging, aud = token_uri)
 * que se canjea en https://oauth2.googleapis.com/token por un access token,
 * cacheado ~55 minutos.
 *
 * Si FCM responde 404 / UNREGISTERED el token del teléfono ya no sirve y se
 * borra de users.fcm_token.
 */
class FcmSender
{
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    public const SEND_URL = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    private const TOKEN_TTL_SECONDS = 3300; // 55 min

    /** Cuenta de servicio válida de la plataforma, o null */
    public function credentials(): ?array
    {
        return TenantSetting::forTenant(null)->fcmServiceAccount();
    }

    /** ¿Se le puede mandar push? (empresa con fcm_enabled + credenciales + token del teléfono) */
    public function canSendTo(object $notifiable): bool
    {
        if (! $notifiable instanceof User || blank($notifiable->fcm_token)) {
            return false;
        }

        try {
            $tenant = $notifiable->tenant_id ? Tenant::find($notifiable->tenant_id) : null;

            return TenantSetting::forTenant($tenant)->fcmEnabled();
        } catch (\Throwable $e) {
            Log::warning('No se pudo resolver la configuración de push: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Envía un push al teléfono del usuario.
     *
     * @param  array<string, mixed>  $data  Se convierte a strings (FCM solo admite string)
     */
    public function send(User $to, string $title, string $body, array $data = []): PushResult
    {
        $started = microtime(true);
        $token   = $to->fcm_token;

        if (blank($token)) {
            return PushResult::failure('El usuario no tiene un dispositivo registrado para notificaciones push.', null, $started, code: 'NO_DEVICE_TOKEN');
        }

        $account = $this->credentials();
        if (! $account) {
            return PushResult::failure('Falta la cuenta de servicio de Firebase en Marca y app > Integraciones (plataforma).', null, $started, code: 'FCM_NOT_CONFIGURED');
        }

        $message = [
            'message' => [
                'token'        => $token,
                'notification' => ['title' => $title, 'body' => $body],
                'data'         => self::stringData($data),
                'android'      => [
                    'priority'     => 'high',
                    'notification' => ['channel_id' => (string) config('services.fcm.android_channel_id', 'tunesa_default')],
                ],
                'apns'         => [
                    'headers' => ['apns-priority' => '10'],
                    'payload' => ['aps' => ['sound' => 'default']],
                ],
            ],
        ];

        try {
            $response = $this->post($account, $message);

            // Token de acceso vencido o revocado: se pide otro una vez
            if ($response->status() === 401) {
                $this->forgetAccessToken($account);
                $response = $this->post($account, $message);
            }
        } catch (PushException $e) {
            return PushResult::failure($e->getMessage(), null, $started, transient: true);
        } catch (ConnectionException|TransferException $e) {
            Log::warning('Error de red al enviar push: ' . $e->getMessage());

            return PushResult::failure('No se pudo conectar con Firebase: ' . NetworkError::describe($e), null, $started, transient: true);
        }

        if ($response->successful()) {
            return PushResult::success($response->status(), $response->json() ?? [], $started);
        }

        $json    = $response->json() ?? [];
        $error   = $json['error'] ?? [];
        $message = (string) ($error['message'] ?? $response->body());

        if (self::isUnregistered($response)) {
            $this->clearToken($to, $token);

            return PushResult::failure("El celular ya no está registrado para notificaciones (se desinstaló la app o se cerró la sesión); se borró el registro. Pide al colaborador que vuelva a iniciar sesión en la app.", $response->status(), $started, $json, tokenCleared: true, code: 'UNREGISTERED');
        }

        return PushResult::failure(
            $message,
            $response->status(),
            $started,
            $json,
            transient: $response->status() === 429 || $response->serverError(),
            code: (string) ($error['status'] ?? 'FCM_ERROR'),
        );
    }

    /**
     * Push a un tema de FCM (sin token de usuario). Lo usa el canal ético: la app
     * se suscribe a un tema aleatorio por denuncia y el servidor nunca sabe qué
     * teléfono lo escucha. No reintenta: si falla, la app lo ve al abrir el caso.
     */
    public function sendToTopic(?Tenant $tenant, string $topic, string $title, string $body, array $data = []): bool
    {
        $account = $this->credentials();
        if (! $account || ! preg_match('/^[a-zA-Z0-9_-]{8,100}$/', $topic) || ! TenantSetting::forTenant($tenant)->fcmEnabled()) {
            return false;
        }

        $message = ['message' => [
            'topic'        => $topic,
            'notification' => ['title' => $title, 'body' => $body],
            'data'         => self::stringData($data),
            'android'      => ['priority' => 'high', 'notification' => ['channel_id' => (string) config('services.fcm.android_channel_id', 'tunesa_default')]],
        ]];

        try {
            return $this->post($account, $message)->successful();
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar el push del canal ético: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Push de prueba para el botón "Probar push" del panel.
     * No mira fcm_enabled (sirve para probar antes de activarlo).
     *
     * @return array{ok: bool, status: ?int, ms: int, response?: array, error?: string}
     */
    public function test(User $to): array
    {
        $result = $this->send(
            $to,
            'Prueba de notificaciones',
            'Si ves este mensaje, las notificaciones push de ' . TenantSetting::forTenant(null)->tradeName() . ' funcionan.',
            ['type' => 'push.test', 'screen' => 'notifications'],
        );

        return $result->toTestArray();
    }

    /** Access token OAuth (cacheado ~55 min) */
    public function accessToken(array $account, bool $fresh = false): string
    {
        $key = $this->cacheKey($account);

        if (! $fresh && is_string($cached = Cache::get($key)) && $cached !== '') {
            return $cached;
        }

        $tokenUri = $account['token_uri'] ?? self::TOKEN_URI;

        try {
            $response = Http::asForm()->timeout(10)->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $this->buildJwt($account),
            ]);
        } catch (ConnectionException|TransferException $e) {
            Log::warning('Error de red al pedir el token de Firebase: ' . $e->getMessage());

            throw new PushException('No se pudo conectar con Google para autorizar Firebase: ' . NetworkError::describe($e), 0, $e);
        }

        $accessToken = $response->json('access_token');
        if (! $response->successful() || ! is_string($accessToken) || $accessToken === '') {
            $detail = $response->json('error_description') ?? $response->json('error') ?? $response->body();

            throw new PushException('Google rechazó la cuenta de servicio de Firebase: ' . (is_string($detail) ? $detail : json_encode($detail)));
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 3600);
        Cache::put($key, $accessToken, max(60, min(self::TOKEN_TTL_SECONDS, $expiresIn - 300)));

        return $accessToken;
    }

    public function forgetAccessToken(array $account): void
    {
        Cache::forget($this->cacheKey($account));
    }

    /** JWT RS256 de la cuenta de servicio para el canje OAuth */
    public function buildJwt(array $account, ?int $now = null): string
    {
        $now ??= time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $account['client_email'],
            'scope' => self::SCOPE,
            'aud'   => $account['token_uri'] ?? self::TOKEN_URI,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $unsigned = self::base64Url(json_encode($header, JSON_UNESCAPED_SLASHES))
            . '.' . self::base64Url(json_encode($claims, JSON_UNESCAPED_SLASHES));

        $key = openssl_pkey_get_private($account['private_key']);
        if ($key === false) {
            throw new PushException('La clave privada de la cuenta de servicio de Firebase no es válida.');
        }

        $signature = '';
        if (! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new PushException('No se pudo firmar el JWT de la cuenta de servicio.');
        }

        return $unsigned . '.' . self::base64Url($signature);
    }

    /** FCM solo admite strings en data */
    public static function stringData(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            $out[(string) $key] = match (true) {
                is_bool($value)   => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                default           => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            };
        }

        return $out;
    }

    public static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function post(array $account, array $message): Response
    {
        return Http::withToken($this->accessToken($account))
            ->acceptJson()
            ->timeout(10)
            ->post(sprintf(self::SEND_URL, $account['project_id']), $message);
    }

    private static function isUnregistered(Response $response): bool
    {
        if ($response->status() === 404) {
            return true;
        }

        foreach ((array) $response->json('error.details', []) as $detail) {
            if (($detail['errorCode'] ?? null) === 'UNREGISTERED') {
                return true;
            }
        }

        return $response->json('error.status') === 'NOT_FOUND';
    }

    /** Borra el token muerto (solo si sigue siendo el mismo: el teléfono pudo registrar otro) */
    private function clearToken(User $user, string $token): void
    {
        User::withoutGlobalScope(TenantScope::class)
            ->whereKey($user->id)
            ->where('fcm_token', $token)
            ->update(['fcm_token' => null]);

        $user->fcm_token = null;
        $user->syncOriginalAttribute('fcm_token');
    }

    private function cacheKey(array $account): string
    {
        return 'fcm:access_token:' . sha1($account['client_email'] . '|' . $account['private_key']);
    }
}
