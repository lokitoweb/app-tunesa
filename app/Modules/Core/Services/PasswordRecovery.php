<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\PasswordHelpRequested;
use App\Modules\Core\Notifications\TemporaryPasswordMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * "Olvidé mi contraseña" por autoservicio (web y app): la persona escribe su correo y le llega
 * una contraseña temporal aleatoria que debe cambiar al ingresar (vence en
 * CredentialProvisioner::TEMP_TTL_HOURS). La contraseña actual no se puede enviar: solo se guarda
 * su huella cifrada.
 *
 *  - Respuesta siempre igual: no revela si el correo existe.
 *  - Una solicitud por persona cada THROTTLE_MINUTES (nadie puede dejar sin acceso a otro a cada rato).
 *  - Si el correo no sale, la contraseña no cambia y se avisa a RR. HH. como antes.
 *  - Queda en la bitácora (sin la contraseña) y se cierran sus sesiones.
 */
class PasswordRecovery
{
    public const THROTTLE_MINUTES = 15;

    public const RESPONSE = 'Listo. Si el correo está registrado y activo, te enviamos una contraseña temporal. Revisa tu bandeja de entrada (y la carpeta de spam): vence en '
        . CredentialProvisioner::TEMP_TTL_HOURS . ' horas y la cambiarás al ingresar.';

    public function __construct(
        private readonly CredentialProvisioner $credentials,
        private readonly AccountDeletion $accounts,
        private readonly AuditLogger $audit,
    ) {}

    /** Envía la contraseña temporal si corresponde. No informa nada hacia afuera (respuesta neutra). */
    public function sendTemporaryPassword(string $email, ?int $tenantId = null): void
    {
        $user = $this->accounts->findUser($email);
        if (! $user || $user->status !== 'active' || ! $user->email
            || ($tenantId && (int) $user->tenant_id !== $tenantId)) {
            return;
        }
        if (! Cache::add("password-recovery:{$user->id}", true, now()->addMinutes(self::THROTTLE_MINUTES))) {
            return;
        }

        $password = $this->credentials->temporaryPassword();
        try {
            // El correo sale dentro de la transacción: si falla, la contraseña anterior sigue valiendo
            DB::transaction(function () use ($user, $password) {
                $user->forceFill($this->credentials->temporaryAttributes($password) + ['remember_token' => Str::random(60)])->save();
                $user->tokens()->delete();
                $user->endWebSessions();
                $user->notify(new TemporaryPasswordMail($password));
            });
        } catch (\Throwable $e) {
            Log::warning("No se pudo enviar la contraseña temporal a {$user->email}: " . $e->getMessage());
            $this->askHumanResources($user);

            return;
        }

        $this->audit->log('auth.password_recovered', $user, ['name' => $user->full_name, 'channel' => 'email']);
    }

    /** Respaldo: si el correo no sale, RR. HH. recibe el pedido para restablecer el acceso desde el panel */
    private function askHumanResources(User $user): void
    {
        try {
            $recipients = $this->accounts->handlers($user);
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new PasswordHelpRequested($user));
            }
        } catch (\Throwable $e) {
            Log::warning('Tampoco se pudo avisar a RR. HH. del pedido de contraseña: ' . $e->getMessage());
        }
    }
}
