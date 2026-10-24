<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\AccountDeletionRequest;
use App\Modules\Core\Models\Scopes\TenantScope;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AccountDeletionRequested;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Pedidos de eliminación de cuenta (Google Play exige ofrecerlo dentro de la
 * app y en una web pública). La cuenta la crea la empresa, así que no se borra
 * sola: queda el pedido y se avisa a RRHH (o al administrador), que elimina
 * los datos y conserva lo que la ley laboral obliga a guardar.
 *
 * Un pedido pendiente de la misma persona no se duplica.
 */
class AccountDeletion
{
    public function request(?User $user, string $email, ?string $reason, string $source): AccountDeletionRequest
    {
        $email = mb_strtolower(trim($email));

        $pending = AccountDeletionRequest::withoutGlobalScope(TenantScope::class)
            ->where('status', 'pending')
            ->when($user, fn ($q) => $q->where('user_id', $user->id), fn ($q) => $q->where('email', $email))
            ->first();

        if ($pending) {
            return $pending;
        }

        $request = AccountDeletionRequest::create([
            'tenant_id' => $user?->tenant_id,
            'user_id'   => $user?->id,
            'email'     => $email,
            'reason'    => filled($reason) ? trim($reason) : null,
            'source'    => $source,
            'status'    => 'pending',
        ]);

        if ($user?->tenant_id) {
            try {
                $recipients = $this->handlers($user);
                if ($recipients->isNotEmpty()) {
                    Notification::send($recipients, new AccountDeletionRequested($request->setRelation('user', $user)));
                }
            } catch (\Throwable $e) {
                Log::warning('No se pudo avisar el pedido de eliminación de cuenta: ' . $e->getMessage(), ['exception' => $e]);
            }
        }

        return $request;
    }

    /** Busca la cuenta por correo en todas las empresas (la web es pública). */
    public function findUser(string $email): ?User
    {
        return User::withoutGlobalScope(TenantScope::class)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
            ->first();
    }

    /** Quién atiende los pedidos sobre la cuenta (eliminarla, recuperar la clave): RRHH de la empresa; si no tiene, su administrador. Nunca la propia persona. */
    public function handlers(User $user): Collection
    {
        $users = fn () => User::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $user->tenant_id)
            ->where('status', 'active')
            ->where('id', '!=', $user->id);

        $hr = $users()->where('role', 'hr_manager')->get();

        return $hr->isNotEmpty() ? $hr : $users()->where('role', 'company_admin')->get();
    }
}
