<?php

namespace App\Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Revisa la configuración antes de salir a producción (o en cada despliegue):
 *
 *   php artisan app:check-production
 *
 * Termina con código 1 si hay algún error, para cortar un pipeline de despliegue.
 * Las advertencias no bloquean, pero conviene resolverlas.
 */
class CheckProductionReadiness extends Command
{
    protected $signature = 'app:check-production';

    protected $description = 'Verifica que la configuración sea segura para producción';

    public function handle(): int
    {
        $errors = [];
        $warnings = [];

        if (config('app.env') !== 'production') {
            $warnings[] = 'APP_ENV no es "production".';
        }
        if (config('app.debug')) {
            $errors[] = 'APP_DEBUG=true expone trazas y datos internos: ponlo en false.';
        }
        if (blank(config('app.key'))) {
            $errors[] = 'APP_KEY está vacío: corre php artisan key:generate.';
        }
        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $errors[] = 'APP_URL debe usar https://.';
        }
        if (! config('session.secure')) {
            $errors[] = 'SESSION_SECURE_COOKIE debe ser true con HTTPS.';
        }
        if (config('queue.default') === 'sync') {
            $warnings[] = 'QUEUE_CONNECTION=sync: los envíos (push, webhooks) bloquean cada petición. Usa database o redis.';
        }
        if (config('mail.default') === 'log') {
            $warnings[] = 'MAIL_MAILER=log: no llegarán los correos de recuperación de contraseña.';
        }
        if (config('logging.channels.single.level') === 'debug') {
            $warnings[] = 'LOG_LEVEL=debug: baja a warning o error para no llenar el disco ni registrar datos de más.';
        }
        if (config('database.default') === 'sqlite') {
            $warnings[] = 'DB_CONNECTION=sqlite no escala con varios servidores ni muchas escrituras concurrentes: usa MySQL o PostgreSQL.';
        }
        if (in_array(config('cache.default'), ['array', 'file'], true)) {
            $warnings[] = 'CACHE_STORE=' . config('cache.default') . ': con varios servidores usa database o redis (límites de intentos y bloqueos compartidos).';
        }
        if (config('integrations.allow_private_urls')) {
            $errors[] = 'INTEGRATIONS_ALLOW_PRIVATE_URLS=true permite webhooks hacia la red interna (SSRF).';
        }
        if (filled(env('SEED_SUPERADMIN_PASSWORD'))) {
            $warnings[] = 'SEED_SUPERADMIN_PASSWORD sigue en el .env: bórrala después de sembrar.';
        }

        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $errors[] = 'No hay conexión con la base de datos.';
        }

        foreach ($errors as $message) {
            $this->components->error($message);
        }
        foreach ($warnings as $message) {
            $this->components->warn($message);
        }
        if (! $errors && ! $warnings) {
            $this->components->info('Configuración lista para producción.');
        }

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
