<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Protección contra fuerza bruta en el login (API móvil, panel y super admin).
 *
 * Dos capas:
 *  1. RateLimiter por empresa + correo + IP: corta ráfagas aunque el correo
 *     no exista (no revela qué cuentas existen).
 *  2. Bloqueo de la cuenta: tras N fallos seguidos la cuenta queda bloqueada
 *     M minutos (users.locked_until). RRHH puede desbloquearla desde el panel.
 */
class LoginGuard
{
    public function maxAttempts(): int
    {
        return (int) config('auth.lockout.max_attempts', 5);
    }

    public function lockMinutes(): int
    {
        return (int) config('auth.lockout.minutes', 15);
    }

    public function throttleKey(Request $request, ?int $tenantId): string
    {
        return 'login:' . ($tenantId ?? 'platform') . ':' . mb_strtolower((string) $request->input('email')) . '|' . $request->ip();
    }

    /**
     * Valida credenciales aplicando ambas capas. Devuelve el usuario si son
     * correctas; lanza ValidationException (422/429/423) si no.
     */
    public function attempt(Request $request, ?User $user, ?int $tenantId): User
    {
        $key = $this->throttleKey($request, $tenantId);

        if (RateLimiter::tooManyAttempts($key, $this->maxAttempts())) {
            throw $this->lockedException(RateLimiter::availableIn($key), 429);
        }

        if ($user?->isLocked()) {
            throw $this->lockedException(now()->diffInSeconds($user->locked_until, true), 423);
        }

        if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
            RateLimiter::hit($key, $this->lockMinutes() * 60);
            $this->registerFailure($user);

            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        RateLimiter::clear($key);

        return $user;
    }

    public function registerSuccess(User $user, Request $request): void
    {
        $attributes = [
            'failed_login_attempts' => 0,
            'locked_until'          => null,
            'last_login_at'         => now(),
            'last_login_ip'         => $request->ip(),
        ];

        // Migración transparente de hashes antiguos (bcrypt → argon2id)
        if (Hash::needsRehash($user->password)) {
            $attributes['password'] = (string) $request->input('password');
        }

        $user->forceFill($attributes)->saveQuietly();
    }

    private function registerFailure(?User $user): void
    {
        if (! $user) {
            return;
        }

        $attempts = $user->failed_login_attempts + 1;

        $user->forceFill($attempts >= $this->maxAttempts()
            ? ['failed_login_attempts' => 0, 'locked_until' => now()->addMinutes($this->lockMinutes())]
            : ['failed_login_attempts' => $attempts]
        )->saveQuietly();

        if ($attempts >= $this->maxAttempts()) {
            app(AuditLogger::class)->log('auth.account_locked', $user, ['ip' => request()->ip()]);
        }
    }

    private function lockedException(int $seconds, int $status): ValidationException
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return ValidationException::withMessages([
            'email' => ["Demasiados intentos fallidos. Tu acceso está bloqueado por {$minutes} min."],
        ])->status($status);
    }
}
