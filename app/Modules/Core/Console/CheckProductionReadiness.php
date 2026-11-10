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
        if (filled(config('app.seed_superadmin.password'))) {
            $warnings[] = 'SEED_SUPERADMIN_PASSWORD sigue en el .env: bórrala después de sembrar.';
        }

        try {
            DB::connection()->getPdo();
            // Segunda capa de la bitácora de marcaciones (la primera es la app): si MySQL no dejó crearla
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
                && ! DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'attendance_punches_immutable'")->n) {
                $warnings[] = 'Falta la protección de base de datos de las marcaciones (MySQL no dejó crear el trigger). '
                    . 'La app las sigue protegiendo; para la segunda capa corre una vez: sudo bash scripts/server-setup.sh y luego php artisan migrate.';
            }
        } catch (\Throwable) {
            $errors[] = 'No hay conexión con la base de datos.';
        }

        // Sin installed.lock, si la base falla un momento /install se abriría y podría reescribir el .env
        if (\App\Installer\InstallState::enabled() && ! is_file(\App\Installer\InstallState::lockFile())) {
            $warnings[] = 'El asistente /install sigue habilitado y falta storage/app/installed.lock: pon INSTALLER_ENABLED=false o crea el archivo.';
        }

        [$leakErrors, $leakWarnings] = $this->leakedSecrets();
        $errors = array_merge($errors, $leakErrors);
        $warnings = array_merge($warnings, $leakWarnings);

        // El cron del scheduler deja una señal cada minuto; sin ella no corren faltas, recordatorios ni la cola
        try {
            $lastRun = (int) cache()->get('scheduler:last_run', 0);
        } catch (\Throwable) {
            $lastRun = 0;
        }
        if (now()->timestamp - $lastRun > 300) {
            $warnings[] = 'Las tareas programadas no corren (faltas, recordatorios, cola de push): como root, sudo bash scripts/server-setup.sh.';
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

    /** Commits públicos del repositorio que llevaron un .env (no se pueden borrar sin reescribir el historial) */
    public const LEAKED_ENV_COMMITS = ['f10f077'];

    /**
     * ¿Este servidor usa algún secreto que quedó en el historial público? Compara, sin mostrar valores,
     * la APP_KEY y las contraseñas de los Super Admin con las de cada .env histórico (git show).
     *
     * @return array{0: list<string>, 1: list<string>} [errores, advertencias]
     */
    public function leakedSecrets(?callable $readEnv = null): array
    {
        $readEnv ??= function (string $commit): ?string {
            $out = @shell_exec('git -C ' . escapeshellarg(base_path()) . ' show ' . escapeshellarg("{$commit}:.env") . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));

            return is_string($out) && $out !== '' ? $out : null;
        };
        $errors = [];
        $warnings = [];

        foreach (self::LEAKED_ENV_COMMITS as $commit) {
            $env = $readEnv($commit);
            if ($env === null) {
                $warnings[] = "No se pudo leer el .env del commit {$commit} para compararlo (sin git en el servidor).";
                continue;
            }
            preg_match('/^APP_KEY=(.*)$/m', $env, $key);
            if (filled($key[1] ?? null) && hash_equals(trim($key[1], " \t\"'\r"), (string) config('app.key'))) {
                $errors[] = "APP_KEY es la misma que quedó en el historial público (commit {$commit}): rótala (ver docs/audit/SECURITY_AUDIT.md).";
            }
            preg_match('/^SUPERADMIN_PASSWORD=(.*)$/m', $env, $password);
            $password = trim($password[1] ?? '', " \t\"'\r");
            if ($password !== '') {
                try {
                    $hashes = \App\Modules\Core\Models\User::withoutGlobalScopes()->where('role', 'super_admin')->pluck('password');
                    if ($hashes->contains(fn ($hash) => $hash && \Illuminate\Support\Facades\Hash::check($password, $hash))) {
                        $errors[] = "Un Super Admin usa la contraseña que quedó en el historial público (commit {$commit}): cámbiala.";
                    }
                } catch (\Throwable) {
                    $warnings[] = 'No se pudo comparar la contraseña de los Super Admin (sin base de datos).';
                }
            }
        }

        return [$errors, $warnings];
    }
}
