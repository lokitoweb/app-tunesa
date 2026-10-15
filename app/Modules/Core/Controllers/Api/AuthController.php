<?php

namespace App\Modules\Core\Controllers\Api;

use App\Modules\Core\Services\TenantContext;
use App\Modules\Core\Controllers\Controller;
use App\Modules\Attendance\Requests\LoginRequest;
use App\Modules\Core\Resources\UserResource;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\LoginGuard;
use App\Modules\Core\Notifications\SecurityAlert;
use App\Modules\Core\Support\NotificationPreferences;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use App\Modules\Integrations\Services\Push\FcmSender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * AuthController
 *
 * Gestiona la autenticación de todos los usuarios mediante Laravel Sanctum.
 * Los tokens de la app móvil vencen a los auth.mobile_token_days días (30 por defecto)
 * y son revocables (logout, logout-all, /auth/sessions). Login con bloqueo por
 * fuerza bruta (App\Modules\Core\Services\LoginGuard). No existe auto-registro público.
 * Los tokens del panel web son de sesión (se destruyen al cerrar).
 *
 * Flujo de autenticación:
 *   POST /api/auth/login  →  Recibe email + password
 *                         →  Valida tenant (ya resuelto por middleware)
 *                         →  Genera Bearer Token de Sanctum
 *                         →  Devuelve token + datos del usuario
 */
class AuthController extends Controller
{
    public const RESET_BY_ADMIN_MESSAGE = 'Para restablecer o cambiar tu contraseña, pide a RRHH que restablezca tu acceso desde el panel.';

    public function __construct(private readonly LoginGuard $guard) {}

    // =========================================================================
    // LOGIN
    // =========================================================================

    /**
     * @OA\Post(
     *   path="/api/auth/login",
     *   summary="Iniciar sesión",
     *   tags={"Auth"},
     *   @OA\RequestBody(required=true,
     *     @OA\JsonContent(
     *       required={"email","password"},
     *       @OA\Property(property="email",    type="string", example="ana.torres@empresa-demo.com"),
     *       @OA\Property(property="password", type="string", example="Employee2024!"),
     *       @OA\Property(property="device_name", type="string", example="iPhone 15 de Ana"),
     *       @OA\Property(property="fcm_token",   type="string")
     *     )
     *   ),
     *   @OA\Header(header="X-Tenant-Slug", required=true, @OA\Schema(type="string", example="empresa-demo"))
     * )
     */
    public function login(LoginRequest $request): JsonResponse
    {
        // El tenant ya está resuelto por el middleware ResolveTenant
        $tenant = current_tenant();

        // Buscar usuario en el tenant actual (aislamiento estricto)
        $user = User::where('tenant_id', $tenant->id)
            ->where('email', $request->email)
            ->first();

        // Credenciales + bloqueo por fuerza bruta (429 ráfaga / 423 cuenta bloqueada)
        $user = $this->guard->attempt($request, $user, $tenant->id);

        return $this->openSession($user, $request);
    }

    /**
     * Login sin código de empresa: la app solo pide correo y contraseña y el
     * servidor reconoce la empresa del usuario (y con ella su rol y su sede).
     *
     * POST /api/v1/auth/login
     *
     * El correo es único en toda la plataforma (índice users.email), así que
     * identifica una sola cuenta. La respuesta trae user.company.slug, que la
     * app guarda para enviar X-Tenant-Slug en el resto de llamadas.
     */
    public function loginByEmail(LoginRequest $request, TenantContext $context): JsonResponse
    {
        // El Super Admin (sin empresa) entra por /api/superadmin/auth/login
        $user = User::whereNotNull('tenant_id')->where('email', $request->email)->with('tenant')->first();

        // Credenciales + bloqueo por fuerza bruta (429 ráfaga / 423 cuenta bloqueada)
        $user = $this->guard->attempt($request, $user, $user?->tenant_id);

        if (! $user->tenant?->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'La cuenta de la empresa está suspendida o cancelada.',
                'code'    => 'TENANT_INACTIVE',
            ], 403);
        }

        // Desde aquí la petición es de esa empresa (global scopes y zona horaria)
        $context->set($user->tenant);

        return $this->openSession($user, $request);
    }

    /** Cuenta activa → token Sanctum del dispositivo y datos del usuario */
    private function openSession(User $user, LoginRequest $request): JsonResponse
    {
        // Verificar que el usuario está activo
        if ($user->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Tu cuenta está ' . match ($user->status) {
                    'inactive'  => 'desactivada',
                    'suspended' => 'suspendida',
                    default     => 'no disponible',
                } . '. Contacta a tu administrador.',
                'code'    => 'USER_INACTIVE',
            ], 403);
        }

        // ¿Dispositivo nuevo para una cuenta que ya había entrado antes? → aviso de seguridad
        $deviceName  = $request->input('device_name', 'mobile_app');
        $isNewDevice = $user->last_login_at !== null && ! $user->tokens()->where('name', $deviceName)->exists();

        $this->guard->registerSuccess($user, $request);

        // Revocar tokens anteriores del mismo dispositivo (evitar sesiones huérfanas)
        $user->tokens()->where('name', $deviceName)->delete();

        // Crear nuevo token Sanctum (revocable, con expiración)
        $token = $user->createToken(
            name:       $deviceName,
            abilities:  $this->getAbilitiesForRole($user->role),
            expiresAt:  now()->addDays((int) config('auth.mobile_token_days', 30)),
        );

        $this->pruneDevices($user);

        // Inicio de sesión en otro teléfono: queda en la bandeja (registro de seguridad), sin push
        if ($isNewDevice) {
            $this->notifySafely($user, (new SecurityAlert('new_device', self::deviceLabel((string) $deviceName), now($user->tenant?->timezone ?: config('app.timezone'))->toDateTimeString()))->withoutPush());
        }

        // Actualizar FCM token si se proporcionó
        if (is_string($fcmToken = $request->input('fcm_token')) && $fcmToken !== '') {
            $user->registerFcmToken($fcmToken);
        }

        return response()->json([
            'success' => true,
            'message' => "Bienvenido, {$user->first_name}.",
            'data'    => [
                'token'      => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                'must_change_password' => (bool) $user->must_change_password,
                'user'       => new UserResource($user->load('tenant')),
            ],
        ]);
    }

    // =========================================================================
    // LOGIN DE PLATAFORMA (Super Admin, sin tenant)
    // =========================================================================

    /**
     * El super admin no pertenece a ninguna empresa, así que no pasa por
     * ResolveTenant. Su token solo sirve para /api/superadmin/*.
     *
     * POST /api/superadmin/auth/login
     */
    public function superAdminLogin(LoginRequest $request): JsonResponse
    {
        $user = User::whereNull('tenant_id')
            ->where('role', Role::SUPER_ADMIN)
            ->where('email', $request->email)
            ->first();

        $user = $this->guard->attempt($request, $user, null);

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        $this->guard->registerSuccess($user, $request);

        $token = $user->createToken(
            name:      $request->input('device_name', 'superadmin'),
            abilities: ['*'],
            expiresAt: now()->addHours(12),
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'token'      => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                'user'       => new UserResource($user),
            ],
        ]);
    }

    // El auto-registro público quedó eliminado (Fase 3): solo Super Admin,
    // Company Admin o RRHH dan de alta colaboradores (panel o /api/admin/employees).

    // =========================================================================
    // RECUPERACIÓN DE CONTRASEÑA
    // =========================================================================

    /**
     * La app no restablece contraseñas (política de tiendas y de la empresa):
     * lo hace RRHH desde el panel ("Restablecer acceso"). Se responde 410 para
     * que las versiones antiguas de la app muestren el aviso.
     *
     * POST /api/auth/forgot-password · /api/v1/auth/forgot-password · /api/auth/reset-password
     */
    public function passwordResetByAdmin(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code'    => 'PASSWORD_RESET_BY_ADMIN',
            'message' => self::RESET_BY_ADMIN_MESSAGE,
        ], 410);
    }

    // =========================================================================
    // LOGOUT
    // =========================================================================

    public function logout(Request $request): JsonResponse
    {
        // Revocar solo el token actual (no todos los dispositivos)
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    /**
     * Cerrar sesión en todos los dispositivos.
     * POST /api/auth/logout-all
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['success' => true, 'message' => 'Se cerró la sesión en todos tus dispositivos.']);
    }

    /**
     * Cerrar sesión en los demás dispositivos (mantiene este).
     * POST /api/auth/logout-others
     */
    public function logoutOthers(Request $request): JsonResponse
    {
        $count = $request->user()->tokens()->where('id', '!=', $request->user()->currentAccessToken()?->id)->delete();

        return response()->json([
            'success' => true,
            'message' => $count ? "Se cerró la sesión en {$count} dispositivo(s)." : 'No tenías sesiones abiertas en otros dispositivos.',
            'data'    => ['closed' => $count],
        ]);
    }

    /**
     * Qué avisos push recibe la persona.
     * GET /api/auth/notification-preferences
     */
    public function notificationPreferences(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => NotificationPreferences::forUser($request->user())]);
    }

    /**
     * PUT /api/auth/notification-preferences  { "trainings": false, ... }
     */
    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        $keys      = array_keys(NotificationPreferences::CATEGORIES);
        $validated = $request->validate(array_fill_keys($keys, ['sometimes', 'boolean']));
        $user      = $request->user();

        $prefs = array_merge($user->notification_preferences ?? [], array_map('boolval', $validated));
        $user->forceFill(['notification_preferences' => array_intersect_key($prefs, array_flip($keys))])->save();

        return response()->json(['success' => true, 'message' => 'Preferencias guardadas.', 'data' => NotificationPreferences::forUser($user)]);
    }

    /**
     * Dispositivos con sesión abierta (tokens vigentes).
     * GET /api/auth/sessions
     */
    public function sessions(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken()?->id;

        return response()->json([
            'success' => true,
            'data'    => $request->user()->tokens()
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->latest('last_used_at')
                ->get()
                ->map(fn ($t) => [
                    'id'           => $t->id,
                    'device'       => $t->name,
                    'label'        => self::deviceLabel($t->name),
                    'created_at'   => $t->created_at?->toIso8601String(),
                    'last_used_at' => $t->last_used_at?->toIso8601String(),
                    'expires_at'   => $t->expires_at?->toIso8601String(),
                    'current'      => $t->id === $current,
                ]),
        ]);
    }

    /**
     * Revocar la sesión de un dispositivo.
     * DELETE /api/auth/sessions/{id}
     */
    public function revokeSession(Request $request, int $id): JsonResponse
    {
        $deleted = $request->user()->tokens()->where('id', $id)->delete();

        abort_if($deleted === 0, 404);

        return response()->json(['success' => true, 'message' => 'Sesión revocada.']);
    }

    // =========================================================================
    // ME — Datos del usuario autenticado
    // =========================================================================

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('tenant', 'branches');

        return response()->json([
            'success' => true,
            'data'    => new UserResource($user),
        ]);
    }

    // =========================================================================
    // ACTUALIZAR PERFIL
    // =========================================================================

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name'  => ['sometimes', 'string', 'max:100'],
            'phone'      => ['sometimes', 'nullable', 'string', 'max:20'],
            'avatar'     => ['sometimes', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'remove_avatar' => ['sometimes', 'boolean'],
        ], ['avatar.max' => 'La foto no puede pesar más de 2 MB.', 'avatar.image' => 'La foto debe ser una imagen JPG o PNG.']);

        // Procesar avatar si se subió uno nuevo (y borrar el anterior)
        $previous = null;
        if ($request->hasFile('avatar')) {
            $previous = $user->avatar_path;
            $validated['avatar_path'] = $request->file('avatar')
                ->store("avatars/{$user->tenant_id}", 'public');
            unset($validated['avatar']);
        } elseif ($request->boolean('remove_avatar') && $user->avatar_path) {
            // Quitar la foto: vuelven las iniciales
            $previous = $user->avatar_path;
            $validated['avatar_path'] = null;
        }
        unset($validated['remove_avatar']);

        $user->update($validated);
        if ($previous) {
            Storage::disk('public')->delete($previous);
        }

        return response()->json([
            'success' => true,
            'message' => 'Perfil actualizado correctamente.',
            'data'    => new UserResource($user->fresh()->load('tenant')),
        ]);
    }

    // =========================================================================
    // CAMBIAR CONTRASEÑA
    // =========================================================================

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        // La contraseña temporal siempre se cambia; fuera de eso, solo si la
        // empresa permite el cambio desde la app (si no, lo hace RRHH)
        if (! $user->must_change_password && ! \App\Modules\Core\Models\TenantSetting::forTenant($user->tenant)->self_password_change) {
            return response()->json([
                'success' => false,
                'code'    => 'PASSWORD_CHANGE_BY_ADMIN',
                'message' => self::RESET_BY_ADMIN_MESSAGE,
            ], 403);
        }

        $request->validate([
            'current_password' => ['required', 'string'],
            'new_password'     => ['required', 'string', 'confirmed', 'different:current_password', PasswordRule::min(8)->letters()->mixedCase()->numbers()],
        ]);

        if (! Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña actual es incorrecta.'],
            ]);
        }

        $user->forceFill([
            'password'                 => $request->new_password,
            'must_change_password'     => false,
            'temp_password_expires_at' => null,
        ])->save();

        // Revocar todos los otros tokens (forzar re-login en otros dispositivos)
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Contraseña actualizada. Los otros dispositivos han sido desconectados.',
        ]);
    }

    // =========================================================================
    // ACTUALIZAR FCM TOKEN (push notifications)
    // =========================================================================

    public function updateFcmToken(Request $request): JsonResponse
    {
        $request->validate([
            'fcm_token' => ['required', 'string', 'max:4096'],
        ]);

        $request->user()->registerFcmToken($request->fcm_token);

        return response()->json(['success' => true]);
    }

    /**
     * Push de prueba al celular desde el que se pide (Perfil > Notificaciones).
     * Sirve para que el colaborador confirme que los avisos le llegan.
     * POST /api/auth/test-push
     */
    public function testPush(Request $request, FcmSender $sender): JsonResponse
    {
        $request->validate(['fcm_token' => ['nullable', 'string', 'max:4096']]);

        /** @var User $user */
        $user = $request->user();

        // La app manda su token actual: así la prueba va a este teléfono aunque
        // el registro anterior no se hubiera guardado.
        if (filled($request->fcm_token) && $request->fcm_token !== $user->fcm_token) {
            $user->registerFcmToken($request->fcm_token);
        }

        if (blank($user->fcm_token)) {
            return response()->json([
                'success' => false,
                'message' => 'Este teléfono todavía no está registrado para recibir avisos. Cierra la app, vuelve a abrirla e inténtalo otra vez.',
                'code'    => 'NO_DEVICE_TOKEN',
            ], 422);
        }

        $result = $sender->test($user);

        return response()->json(! empty($result['ok'])
            ? ['success' => true, 'message' => 'Aviso de prueba enviado. Debería aparecer en unos segundos.']
            : ['success' => false, 'message' => (string) ($result['error'] ?? 'No se pudo enviar el aviso de prueba.'), 'code' => 'PUSH_FAILED'],
            ! empty($result['ok']) ? 200 : 422);
    }

    // =========================================================================
    // HELPERS PRIVADOS
    // =========================================================================

    /**
     * Mantiene como máximo N dispositivos con sesión abierta (los más antiguos se revocan).
     */
    /** Nombre legible del dispositivo: la app manda app_<plataforma>_<id de instalación> */
    public static function deviceLabel(string $name): string
    {
        if (preg_match('/^app_(android|ios|iOS|macos|windows|linux|fuchsia)_/i', $name, $m)) {
            return match (strtolower($m[1])) {
                'android' => 'Teléfono Android',
                'ios'     => 'iPhone',
                default   => 'App en ' . ucfirst(strtolower($m[1])),
            };
        }

        return Str::limit($name, 60);
    }

    /** Un aviso que falla (correo, cola) no debe tumbar el login */
    private function notifySafely(User $user, SecurityAlert $alert): void
    {
        try {
            $user->notify($alert);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function pruneDevices(User $user): void
    {
        $max = (int) config('auth.max_devices', 3);

        $keep = $user->tokens()->latest('id')->limit($max)->pluck('id');
        $user->tokens()->whereNotIn('id', $keep)->delete();
    }

    /**
     * Define las abilities del token según el rol.
     * (Por defecto Sanctum acepta todas, pero esto permite granularidad futura)
     */
    private function getAbilitiesForRole(string $role): array
    {
        return match ($role) {
            'super_admin'   => ['*'],
            'company_admin' => ['*'],
            'hr_manager'    => ['attendance:read', 'attendance:write', 'employee:read', 'employee:write', 'payslip:write'],
            'supervisor'    => ['attendance:read', 'employee:read'],
            'employee'      => ['attendance:write', 'attendance:read', 'payslip:read'],
            default         => ['attendance:read'],
        };
    }
}
