<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use Illuminate\Support\Str;

/**
 * Credenciales que aprovisiona el backend (la app no restablece contraseñas):
 *
 *  - Correo de acceso [DNI]@dominio, con el dominio de "Marca y app"
 *    (login_email_domain). Si ya existe (p. ej. un reingreso): [DNI]-2@dominio.
 *  - Clave inicial = número de documento (initialPassword); temporal: vence a las TEMP_TTL_HOURS horas y se
 *    debe cambiar en el primer ingreso. Se muestra una sola vez a RRHH.
 */
class CredentialProvisioner
{
    public const TEMP_TTL_HOURS = 72;

    public const TEMP_LENGTH = 12;

    public function emailFor(?Tenant $tenant, string $documentNumber, ?int $ignoreUserId = null): string
    {
        $local  = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $documentNumber)) ?: 'colaborador';
        $domain = TenantSetting::forTenant($tenant)->loginEmailDomain();

        $email = "{$local}@{$domain}";
        for ($n = 2; $this->taken($email, $ignoreUserId); $n++) {
            $email = "{$local}-{$n}@{$domain}";
        }

        return $email;
    }

    /**
     * Clave inicial (alta y restablecimiento por RRHH): el número de documento, solo
     * dígitos (política de la empresa). Sigue siendo temporal: vence y se cambia en el
     * primer ingreso, así quien conozca el DNI no queda con acceso. Sin documento
     * numérico, una temporal aleatoria.
     */
    public function initialPassword(?string $documentNumber): string
    {
        $digits = preg_replace('/\D/', '', (string) $documentNumber);

        return strlen($digits) >= 8 ? $digits : $this->temporaryPassword();
    }

    /** Contraseña temporal: mayúsculas, minúsculas y números, sin caracteres que se confunden (0/O, 1/l/I) */
    public function temporaryPassword(): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789'];
        $all  = implode('', $sets);

        $chars = array_map(fn ($set) => $set[random_int(0, strlen($set) - 1)], $sets);
        while (count($chars) < self::TEMP_LENGTH) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        shuffle($chars);

        return implode('', $chars);
    }

    /** Atributos de una contraseña temporal nueva (alta o restablecimiento) */
    public function temporaryAttributes(string $password): array
    {
        return [
            'password'                 => $password,
            'must_change_password'     => true,
            'temp_password_expires_at' => now()->addHours(self::TEMP_TTL_HOURS),
            'failed_login_attempts'    => 0,
            'locked_until'             => null,
        ];
    }

    /**
     * RRHH restablece el acceso desde el panel: contraseña temporal nueva,
     * desbloqueo y cierre de todas las sesiones de la app. Devuelve la
     * contraseña para mostrarla una sola vez.
     */
    public function resetAccess(User $user): string
    {
        $password = $this->initialPassword($user->document_number);

        $user->forceFill($this->temporaryAttributes($password) + ['remember_token' => Str::random(60)])->save();
        $user->tokens()->delete();
        $user->endWebSessions(); // también sus sesiones abiertas del panel

        return $password;
    }

    private function taken(string $email, ?int $ignoreUserId): bool
    {
        return User::withoutGlobalScopes()->withTrashed()
            ->where('email', $email)
            ->when($ignoreUserId, fn ($q) => $q->whereKeyNot($ignoreUserId))
            ->exists();
    }
}
