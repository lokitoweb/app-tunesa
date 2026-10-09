<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * AuthController
 *
 * Gestiona la autenticación de todos los usuarios mediante Laravel Sanctum.
 * Los tokens de la app móvil tienen una duración de 30 días.
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

        // Verificar credenciales
        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

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

        // Revocar tokens anteriores del mismo dispositivo (evitar sesiones huérfanas)
        $deviceName = $request->input('device_name', 'mobile_app');
        $user->tokens()->where('name', $deviceName)->delete();

        // Crear nuevo token Sanctum
        $token = $user->createToken(
            name:       $deviceName,
            abilities:  $this->getAbilitiesForRole($user->role),
            expiresAt:  now()->addDays(30),
        );

        // Actualizar FCM token si se proporcionó
        if ($fcmToken = $request->input('fcm_token')) {
            $user->update(['fcm_token' => $fcmToken]);
        }

        return response()->json([
            'success' => true,
            'message' => "Bienvenido, {$user->first_name}.",
            'data'    => [
                'token'      => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
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

        if (! $user || ! Hash::check($request->password, $user->password) || $user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

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

    // =========================================================================
    // REGISTRO (auto-registro de colaboradores)
    // =========================================================================

    /**
     * Auto-registro de un colaborador en la empresa resuelta.
     *
     * Solo si la empresa lo habilitó (settings.allow_self_registration).
     * La cuenta nace INACTIVA: RRHH debe activarla antes de poder iniciar sesión.
     *
     * POST /api/auth/register
     */
    public function register(Request $request): JsonResponse
    {
        $tenant = current_tenant();

        if (! $tenant->setting('allow_self_registration', false)) {
            return response()->json([
                'success' => false,
                'message' => 'Tu empresa no permite el auto-registro. Solicita tu cuenta a RRHH.',
                'code'    => 'REGISTRATION_DISABLED',
            ], 403);
        }

        $validated = $request->validate([
            'first_name'      => ['required', 'string', 'max:100'],
            'last_name'       => ['required', 'string', 'max:100'],
            'email'           => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'        => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
            'document_type'   => ['sometimes', 'in:dni,passport,ce'],
            'document_number' => ['required', 'string', 'max:20'],
            'phone'           => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        $user = User::create(array_merge($validated, [
            'tenant_id' => $tenant->id,
            'role'      => Role::EMPLOYEE,
            'status'    => 'inactive',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Registro recibido. RRHH debe activar tu cuenta antes de que puedas ingresar.',
            'data'    => ['id' => $user->id, 'email' => $user->email, 'status' => $user->status],
        ], 201);
    }

    // =========================================================================
    // RECUPERACIÓN DE CONTRASEÑA
    // =========================================================================

    /**
     * Envía el enlace de restablecimiento al correo (si existe en la empresa).
     * Siempre responde igual para no revelar qué correos están registrados.
     *
     * POST /api/auth/forgot-password
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        // El broker consulta User con el TenantScope activo: solo encuentra
        // usuarios de la empresa resuelta.
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'success' => true,
            'message' => 'Si el correo está registrado, recibirás un enlace para restablecer tu contraseña.',
        ]);
    }

    /**
     * Restablece la contraseña con el token recibido por correo.
     *
     * POST /api/auth/reset-password
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password'       => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                // Cerrar sesión en todos los dispositivos
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['El enlace de restablecimiento no es válido o expiró.'],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Contraseña restablecida. Ya puedes iniciar sesión.',
        ]);
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
        ]);

        // Procesar avatar si se subió uno nuevo
        if ($request->hasFile('avatar')) {
            $validated['avatar_path'] = $request->file('avatar')
                ->store("avatars/{$user->tenant_id}", 'public');
            unset($validated['avatar']);
        }

        $user->update($validated);

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
        $request->validate([
            'current_password' => ['required', 'string'],
            'new_password'     => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña actual es incorrecta.'],
            ]);
        }

        $user->update(['password' => $request->new_password]);

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
            'fcm_token' => ['required', 'string'],
        ]);

        $request->user()->update(['fcm_token' => $request->fcm_token]);

        return response()->json(['success' => true]);
    }

    // =========================================================================
    // HELPERS PRIVADOS
    // =========================================================================

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
