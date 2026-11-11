<?php

namespace App\Modules\Core\Console;

use App\Modules\Core\Middleware\EnsureSupportedAppVersion;
use App\Modules\Core\Models\TenantSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * La última compilación de producción de la app en GitHub ("App móvil: APK" en master, terminada bien y con el
 * artefacto tunesa-apk-prod-release) pasa a ser la "Última versión publicada" de Marca y app. Desde ahí rige lo
 * que ya existía: las apps más viejas ven el aviso y llega el push "Nueva versión" (solo a quien no la tiene).
 * Corre cada 2 minutos; sin compilación nueva, una sola petición a GitHub.
 * Versión = major.minor de mobile/pubspec.yaml + n.º de compilación, igual que el workflow (1.6.160).
 * Nunca baja la versión guardada. Repo privado: APP_RELEASES_TOKEN (lectura de Actions) en el .env.
 */
class SyncAppVersion extends Command
{
    protected $signature = 'app:sync-version';

    protected $description = 'Toma la última compilación de producción de la app en GitHub como "Última versión publicada"';

    /** Última compilación ya revisada */
    public const LAST_RUN = 'app-sync-version:last-run';

    public function handle(): int
    {
        $repo = config('services.app_releases.repo');
        $base = preg_match('/^version:\s*(\d+\.\d+)\./m', (string) @file_get_contents(base_path('mobile/pubspec.yaml')), $m) ? $m[1] : null;
        if (! $repo || ! $base) {
            $this->warn('Sin repositorio o sin mobile/pubspec.yaml: nada que sincronizar.');

            return self::SUCCESS;
        }

        $api = fn (string $path) => Http::acceptJson()->timeout(15)
            ->withToken((string) config('services.app_releases.token'), filled(config('services.app_releases.token')) ? 'Bearer' : '')
            ->get("https://api.github.com/repos/{$repo}/{$path}");

        $runs = $api('actions/workflows/' . config('services.app_releases.workflow') . '/runs?branch=master&status=success&per_page=10');
        if (! $runs->successful()) {
            $this->warn("GitHub respondió {$runs->status()}; se reintenta en la próxima vuelta.");

            return self::SUCCESS;
        }

        // Sin compilaciones nuevas desde la vuelta anterior: una sola petición (cada 2 min = 30 por hora, bajo el límite de 60)
        $newest = $runs->json('workflow_runs.0.id');
        if ($newest && \Illuminate\Support\Facades\Cache::get(self::LAST_RUN) === $newest) {
            return self::SUCCESS;
        }

        // La más nueva que sea de producción (una manual de staging o túnel no cuenta)
        foreach ($runs->json('workflow_runs', []) as $run) {
            $artifacts = $api("actions/runs/{$run['id']}/artifacts")->json('artifacts', []);
            if (collect($artifacts)->contains(fn ($a) => str_starts_with((string) $a['name'], 'tunesa-apk-prod-release'))) {
                $version = "{$base}.{$run['run_number']}";
                EnsureSupportedAppVersion::rememberNewerVersion($version, TenantSetting::forTenant(null), null);
                $this->info("Última compilación de producción: {$version}");
                \Illuminate\Support\Facades\Cache::forever(self::LAST_RUN, $newest);

                return self::SUCCESS;
            }
        }
        \Illuminate\Support\Facades\Cache::forever(self::LAST_RUN, $newest);
        $this->line('Sin compilaciones de producción recientes.');

        return self::SUCCESS;
    }
}
