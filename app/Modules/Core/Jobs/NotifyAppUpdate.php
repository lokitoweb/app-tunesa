<?php

namespace App\Modules\Core\Jobs;

use App\Modules\Core\Models\Scopes\TenantScope;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AppUpdateAvailable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Push "Nueva versión de la app" a los colaboradores con teléfono registrado.
 *
 * - Fila de una empresa ($tenantId): a esa empresa.
 * - Fila global (null): a las empresas que heredan la última versión de la
 *   plataforma (su fila no define latest_version).
 *
 * Se envía la versión efectiva de cada empresa: si cambió otra vez antes de
 * correr el job, no se avisa de una versión que ya no es la última.
 */
class NotifyAppUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly ?int $tenantId, public readonly string $version) {}

    public function handle(): void
    {
        foreach ($this->tenants() as $tenant) {
            $settings = TenantSetting::forTenant($tenant);
            if ($settings->latest_version !== $this->version) {
                continue;
            }

            // Obligatoria si la empresa exige actualizar y la mínima ya es esta versión
            $required = $settings->force_update
                && version_compare(TenantSetting::normalizeVersion($settings->min_supported_version ?: '0'), TenantSetting::normalizeVersion($this->version), '>=');
            $notification = new AppUpdateAvailable($this->version, $required);

            User::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->whereNotNull('fcm_token')
                ->chunkById(200, fn ($users) => Notification::send($users, $notification));
        }
    }

    /** @return iterable<Tenant> */
    private function tenants(): iterable
    {
        $query = Tenant::query()->where('status', 'active');

        if ($this->tenantId !== null) {
            return $query->whereKey($this->tenantId)->get();
        }

        $ownVersion = TenantSetting::query()->whereNotNull('tenant_id')->whereNotNull('latest_version')->pluck('tenant_id');

        return $query->whereNotIn('id', $ownVersion)->cursor();
    }
}
