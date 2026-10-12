<?php

namespace App\Modules\Core\Console;

use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Support\PlatformUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Cambia la dirección pública del servidor en un solo paso (por ejemplo, al
 * levantar un túnel nuevo de Cloudflare):
 *
 *   php artisan app:use-url https://nuevo-tunel.trycloudflare.com
 *   php artisan app:use-url https://nuevo-tunel.trycloudflare.com --dry-run
 *
 *  1. Escribe APP_URL en .env y limpia la caché de configuración.
 *  2. En Marca y app / Integraciones de todas las empresas, reemplaza los
 *     túneles viejos guardados (dirección para la app) y el receptor propio
 *     de webhooks que quedó con el dominio anterior.
 *  3. Reinicia los workers de la cola para que tomen la URL nueva.
 */
class UsePublicUrl extends Command
{
    protected $signature = 'app:use-url
                            {url : Dirección pública nueva, con https:// y sin /api}
                            {--dry-run : Solo muestra lo que cambiaría}';

    protected $description = 'Usa una dirección pública nueva (APP_URL) y corrige las URLs viejas guardadas';

    public function handle(): int
    {
        $url = rtrim(preg_replace('#/api/?$#i', '', trim((string) $this->argument('url'))), '/');

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with($url, 'https://') || parse_url($url, PHP_URL_PATH)) {
            $this->error('Escribe la dirección completa con https:// y sin rutas, por ejemplo https://mi-tunel.trycloudflare.com');

            return self::INVALID;
        }

        $dry = (bool) $this->option('dry-run');
        $previous = PlatformUrl::current();
        $this->line("APP_URL actual: {$previous}");
        $this->line("APP_URL nueva:  {$url}");

        // 1. .env
        $envChanged = $this->writeEnv($url, $dry);

        // A partir de aquí todo se calcula con la URL nueva
        config(['app.url' => $url]);

        // 2. Datos guardados con el dominio viejo
        $changes = [];
        TenantSetting::withoutGlobalScopes()->get()->each(function (TenantSetting $row) use ($url, $dry, &$changes) {
            $owner = $row->tenant_id ? "empresa #{$row->tenant_id}" : 'plataforma';
            $update = [];

            if ($row->api_base_url && PlatformUrl::isStaleTunnel($row->api_base_url)) {
                $update['api_base_url'] = $url;
            }
            foreach (['webhook_url', 'notification_endpoint'] as $field) {
                $value = $row->{$field};
                if ($value && PlatformUrl::isOwnReceiver($value) && PlatformUrl::webhookTarget($value) !== $value) {
                    $update[$field] = PlatformUrl::webhookTarget($value);
                }
            }

            foreach ($update as $field => $new) {
                $changes[] = [$owner, $field, $row->{$field}, $new];
            }
            if ($update && ! $dry) {
                TenantSetting::withoutGlobalScopes()->whereKey($row->getKey())->update($update);
            }
        });

        if ($changes) {
            $this->table(['Dónde', 'Campo', 'Antes', 'Ahora'], $changes);
        } else {
            $this->info('No había direcciones viejas guardadas en Marca y app ni en Integraciones.');
        }

        if ($dry) {
            $this->warn('Simulación: no se cambió nada.');

            return self::SUCCESS;
        }

        // 3. Caché de configuración y workers
        Artisan::call('config:clear');
        Artisan::call('queue:restart');

        $this->info(($envChanged ? 'Listo: APP_URL actualizada en .env' : 'Listo') . ', configuración recargada y cola reiniciada.');
        $this->line('Si usas `php artisan serve` o un worker propio (Supervisor), reinícialo para que tome la URL nueva.');
        $this->line("Prueba: {$url}/api/v1/app/config");

        return self::SUCCESS;
    }

    private function writeEnv(string $url, bool $dry): bool
    {
        $path = app()->environmentFilePath();
        if (! is_file($path) || ! is_writable($path)) {
            $this->warn("No se pudo escribir {$path}: pon APP_URL={$url} a mano y corre php artisan config:clear.");

            return false;
        }

        $env = (string) file_get_contents($path);
        $line = "APP_URL={$url}";
        $new = preg_match('/^APP_URL=.*$/m', $env)
            ? preg_replace('/^APP_URL=.*$/m', $line, $env)
            : rtrim($env) . "\n{$line}\n";

        if ($new === $env) {
            return false;
        }
        if (! $dry) {
            file_put_contents($path, $new);
        }

        return true;
    }
}
