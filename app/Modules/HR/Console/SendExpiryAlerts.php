<?php

namespace App\Modules\HR\Console;

use App\Modules\Core\Models\Tenant;
use App\Modules\HR\Services\ExpiryAlerts;
use Illuminate\Console\Command;

/**
 * Alertas de vencimientos (documentos, contratos, capacitaciones, vehículos) por correo y Telegram.
 * Lo corre el scheduler cada mañana (routes/console.php).
 */
class SendExpiryAlerts extends Command
{
    protected $signature = 'hr:expiry-alerts {--tenant= : Solo esta empresa (id)}';

    protected $description = 'Envía las alertas de vencimientos a los destinatarios de cada empresa';

    public function handle(ExpiryAlerts $alerts): int
    {
        Tenant::where('status', 'active')->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')
            ->each(function (Tenant $tenant) use ($alerts) {
                $r = rescue(fn () => $alerts->send($tenant), ['items' => 0, 'email' => false, 'telegram' => 0, 'skipped' => 'error (ver el log)'], report: true);
                $this->line("{$tenant->company_name}: " . ($r['skipped'] ?? "{$r['items']} aviso(s), correo " . ($r['email'] ? 'sí' : 'no') . ", Telegram {$r['telegram']}"));
            });

        return self::SUCCESS;
    }
}
