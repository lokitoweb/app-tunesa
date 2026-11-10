<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\PasswordHelpRequested;
use App\Modules\Core\Notifications\PasswordResetLinkMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * "Olvidé mi contraseña" por autoservicio (web y app), aprobado en la auditoría (SECURITY_AUDIT.md §3.3):
 *
 *  - El pedido NO cambia nada: llega al correo de la cuenta un enlace de un solo uso que vence en
 *    TOKEN_TTL_MINUTES. La contraseña actual sigue funcionando; saber el correo o el DNI de alguien no
 *    alcanza para tomar ni bloquear su cuenta.
 *  - Recién al abrir el enlace y escribir una nueva se cambia la contraseña, se cierran las sesiones y el
 *    enlace se invalida. Un pedido nuevo invalida el anterior.
 *  - Respuesta siempre igual (no revela si el correo existe) y un pedido por persona cada THROTTLE_MINUTES,
 *    además del límite por IP de las rutas.
 *  - Sin un correo que salga de verdad, RR. HH. recibe el pedido (y restablece el acceso con el DNI, igual que hoy).
 *  - La contraseña inicial con el DNI y su cambio obligatorio no se tocan (CredentialProvisioner).
 */
class PasswordRecovery
{
    public const THROTTLE_MINUTES = 15;

    public const TOKEN_TTL_MINUTES = 30;

    public const RESPONSE = 'Listo. Si el correo está registrado y activo, te enviamos un enlace para crear una nueva contraseña. '
        . 'Revisa tu bandeja de entrada (y la carpeta de spam): vence en ' . self::TOKEN_TTL_MINUTES . ' minutos. Tu contraseña actual sigue funcionando hasta que la cambies.';

    public function __construct(
        private readonly AccountDeletion $accounts,
        private readonly AuditLogger $audit,
    ) {}

    /** Envía el enlace si corresponde. No informa nada hacia afuera (respuesta neutra). */
    public function sendResetLink(string $email, ?int $tenantId = null): void
    {
        $user = $this->accounts->findUser($email);
        if (! $user || $user->status !== 'active' || ! $user->email
            || ($tenantId && (int) $user->tenant_id !== $tenantId)) {
            return;
        }
        if (! Cache::add("password-recovery:{$user->id}", true, now()->addMinutes(self::THROTTLE_MINUTES))) {
            return;
        }
        if (! self::mailDelivers($user)) {
            Log::warning("Recuperación de contraseña de usuario #{$user->id}: el servidor no envía correos (MAIL_MAILER=" . config('mail.default') . '); se avisó a RR. HH.');
            $this->askHumanResources($user);

            return;
        }

        $token = Str::random(64);
        try {
            // Un enlace vigente por cuenta: guardar el nuevo invalida el anterior. Solo se guarda su hash.
            DB::table('password_reset_tokens')->updateOrInsert(['email' => $user->email], ['token' => hash('sha256', $token), 'created_at' => now()]);
            $user->notify(new PasswordResetLinkMail(route('password.reset', ['token' => $token]), self::TOKEN_TTL_MINUTES));
        } catch (\Throwable $e) {
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            Log::warning("No se pudo enviar el enlace de recuperación a usuario #{$user->id}: " . $e->getMessage());
            $this->askHumanResources($user);

            return;
        }

        $this->audit->log('auth.password_reset_requested', $user, ['name' => $user->full_name, 'channel' => 'email']);
    }

    /** Dueño de un enlace vigente, o null si no existe, venció o la cuenta ya no está activa */
    public function userForToken(string $token): ?User
    {
        $row = DB::table('password_reset_tokens')->where('token', hash('sha256', $token))->first();
        if (! $row) {
            return null;
        }
        if (now()->subMinutes(self::TOKEN_TTL_MINUTES)->gt($row->created_at)) {
            DB::table('password_reset_tokens')->where('email', $row->email)->delete();

            return null;
        }
        $user = User::withoutGlobalScopes()->where('email', $row->email)->first();

        return $user && $user->status === 'active' ? $user : null;
    }

    /** Cambia la contraseña con el enlace (una sola vez). false si el enlace ya no sirve. */
    public function reset(string $token, string $password): bool
    {
        return DB::transaction(function () use ($token, $password) {
            $user = $this->userForToken($token);
            // Se borra antes de cambiar: dos envíos simultáneos con el mismo enlace no lo usan dos veces
            if (! $user || ! DB::table('password_reset_tokens')->where('token', hash('sha256', $token))->delete()) {
                return false;
            }
            $user->completePasswordReset(Hash::make($password));
            $user->endWebSessions();
            $this->audit->log('auth.password_recovered', $user, ['name' => $user->full_name, 'channel' => 'email']);

            return true;
        });
    }

    /** ¿El correo de esta persona sale de verdad? SMTP propio de su empresa, o un mailer real en el servidor */
    public static function mailDelivers(User $user): bool
    {
        return \App\Modules\Core\Models\TenantSetting::forTenant($user->tenant)->smtp() !== null
            || ! in_array(config('mail.default'), ['log', 'array'], true);
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
