<?php

namespace App\Modules\Core\Console;

use App\Modules\Core\Models\TenantSetting;
use App\Modules\Ethics\Models\EthicsCase;
use App\Modules\Ethics\Models\EthicsCaseMessage;
use App\Modules\HR\Models\EmployeeProfile;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Rotación de APP_KEY sin perder datos (SECURITY_AUDIT.md §2): con la clave nueva en APP_KEY y la anterior en
 * APP_PREVIOUS_KEYS, vuelve a cifrar con la nueva cada columna `encrypted` (denuncias de ética, datos bancarios,
 * credenciales por empresa). Nunca muestra valores. Si alguna no se puede descifrar, no cambia esa celda y
 * termina con error: no se debe quitar APP_PREVIOUS_KEYS hasta que salga limpio.
 *
 *   php artisan app:reencrypt --dry-run   # cuenta lo que haría
 *   php artisan app:reencrypt
 */
class ReencryptSecrets extends Command
{
    /** Modelos con columnas `encrypted` (ReencryptSecretsTest verifica que no falte ninguno) */
    public const MODELS = [TenantSetting::class, EthicsCase::class, EthicsCaseMessage::class, EmployeeProfile::class];

    protected $signature = 'app:reencrypt {--dry-run : Solo cuenta, no guarda}';

    protected $description = 'Vuelve a cifrar con la APP_KEY actual los datos cifrados con claves anteriores';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $failed = 0;

        foreach (self::MODELS as $class) {
            $model = new $class();
            $columns = self::encryptedColumns($model);
            $done = 0;
            DB::table($model->getTable())->select(array_merge([$model->getKeyName()], $columns))->orderBy($model->getKeyName())
                ->chunk(500, function ($rows) use ($model, $columns, $dry, &$done, &$failed) {
                    foreach ($rows as $row) {
                        $changes = [];
                        foreach ($columns as $column) {
                            if ($row->{$column} === null || $row->{$column} === '') {
                                continue;
                            }
                            try {
                                $changes[$column] = Crypt::encryptString(Crypt::decryptString($row->{$column}));
                            } catch (DecryptException) {
                                $failed++;
                            }
                        }
                        if ($changes && ! $dry) {
                            DB::table($model->getTable())->where($model->getKeyName(), $row->{$model->getKeyName()})->update($changes);
                        }
                        $done += count($changes);
                    }
                });
            $this->line(sprintf('%s: %d valor(es) %s', $model->getTable(), $done, $dry ? 'por re-cifrar' : 're-cifrados'));
        }

        if ($failed) {
            $this->error("{$failed} valor(es) no se pudieron descifrar con APP_KEY ni APP_PREVIOUS_KEYS: no se tocaron. No quites la clave anterior.");

            return self::FAILURE;
        }
        $this->info($dry ? 'Vista previa: nada cambió.' : 'Listo. Verifica la app y luego quita APP_PREVIOUS_KEYS.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    public static function encryptedColumns(\Illuminate\Database\Eloquent\Model $model): array
    {
        return array_keys(array_filter($model->getCasts(), fn ($cast) => is_string($cast) && str_starts_with($cast, 'encrypted')));
    }
}
