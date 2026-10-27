<?php

namespace App\Installer\Http;

use App\Installer\EnvWriter;
use App\Installer\InstallState;
use App\Installer\Requirements;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use PDO;

/**
 * Asistente de instalación en 5 pasos: requisitos, base de datos,
 * migraciones, Super Admin y bloqueo. Cada paso comprueba el estado real
 * (requisitos, conexión, tablas) en vez de confiar en la sesión, así un
 * paso no se puede saltar escribiendo la URL.
 */
class InstallerController
{
    public const STEPS = [
        1 => 'Requisitos',
        2 => 'Base de datos',
        3 => 'Estructura',
        4 => 'Super Admin',
        5 => 'Listo',
    ];

    public function requirements(): View
    {
        return $this->view(1, ['req' => Requirements::check()]);
    }

    public function database(): View|RedirectResponse
    {
        if (! Requirements::check()['ok']) {
            return redirect()->route('installer.requirements');
        }

        $db = config('database.connections.' . config('database.default'), []);

        return $this->view(2, [
            'defaults' => [
                'driver'   => in_array(config('database.default'), ['mysql', 'mariadb'], true) ? config('database.default') : 'mysql',
                'host'     => $db['host'] ?? '127.0.0.1',
                'port'     => $db['port'] ?? '3306',
                'database' => ($db['database'] ?? '') === database_path('database.sqlite') ? '' : ($db['database'] ?? ''),
                'username' => $db['username'] ?? '',
            ],
        ]);
    }

    public function testDatabase(Request $request): JsonResponse
    {
        $data = $this->validatedDatabase($request);
        $error = $this->tryConnect($data);

        return response()->json([
            'ok'      => $error === null,
            'message' => $error ?? 'Conexión correcta. Puedes continuar.',
        ], $error === null ? 200 : 422);
    }

    public function saveDatabase(Request $request): RedirectResponse
    {
        abort_unless(Requirements::check()['ok'], 409);

        $data = $this->validatedDatabase($request);
        if ($error = $this->tryConnect($data)) {
            return back()->withInput($request->except('password'))->withErrors(['database' => $error]);
        }

        EnvWriter::forApp()->set(self::domainSettings($request) + [
            'APP_ENV'       => 'production',
            'APP_DEBUG'     => false,
            'LOG_LEVEL'     => 'warning',
            'DB_CONNECTION' => $data['driver'],
            'DB_HOST'       => $data['host'],
            'DB_PORT'       => (string) $data['port'],
            'DB_DATABASE'   => $data['database'],
            'DB_USERNAME'   => $data['username'],
            'DB_PASSWORD'   => (string) ($data['password'] ?? ''),
        ]);

        return redirect()->route('installer.migrate');
    }

    public function migrate(): View|RedirectResponse
    {
        if (! InstallState::databaseConnects()) {
            return redirect()->route('installer.database');
        }

        return $this->view(3, ['migrated' => InstallState::migrated()]);
    }

    public function runMigrations(): JsonResponse
    {
        abort_unless(InstallState::databaseConnects(), 409, 'Primero configura la base de datos.');
        @set_time_limit(600);

        try {
            Artisan::call('migrate', ['--force' => true]);
            $output = Artisan::output();
            // Solo la estructura: roles y permisos, sin empresas ni usuarios de prueba
            Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => 'Falló la migración: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'ok'      => true,
            'message' => 'Tablas, roles y permisos creados.',
            'log'     => mb_substr(trim($output), -4000),
        ]);
    }

    public function admin(): View|RedirectResponse
    {
        if (! InstallState::migrated()) {
            return redirect()->route('installer.migrate');
        }

        return $this->view(4, ['defaults' => [
            'trade_name'         => config('app.name'),
            'login_email_domain' => parse_url((string) request()->root(), PHP_URL_HOST) ?: '',
        ]]);
    }

    public function saveAdmin(Request $request): View
    {
        abort_unless(InstallState::migrated(), 409);

        $request->merge(['login_email_domain' => ltrim(trim((string) $request->input('login_email_domain')), '@')]);
        $data = $request->validate([
            'trade_name'         => ['required', 'string', 'max:120'],
            'login_email_domain' => ['required', 'string', 'max:150', 'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/i'],
            'timezone'           => ['required', 'timezone:all'],
            'first_name'         => ['required', 'string', 'max:100'],
            'last_name'          => ['required', 'string', 'max:100'],
            'email'              => ['required', 'email:rfc', 'max:150', 'unique:users,email'],
            'password'           => ['required', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
        ]);

        DB::transaction(function () use ($data) {
            User::create([
                'tenant_id'            => null,
                'first_name'           => $data['first_name'],
                'last_name'            => $data['last_name'],
                'email'                => strtolower($data['email']),
                'password'             => Hash::make($data['password']),
                'role'                 => 'super_admin',
                'status'               => 'active',
                'must_change_password' => false,
            ]);

            $platform = TenantSetting::platform();
            $platform->forceFill([
                'trade_name'         => $data['trade_name'],
                'login_email_domain' => strtolower(ltrim($data['login_email_domain'], '@')),
            ])->save();
        });

        EnvWriter::forApp()->set([
            'APP_NAME'     => $data['trade_name'],
            'APP_TIMEZONE' => $data['timezone'],
        ]);

        // Paso 5: bloquear el asistente y dejar el sistema listo
        InstallState::markInstalled();
        foreach (['storage:link', 'config:clear', 'route:clear', 'view:clear'] as $command) {
            try {
                Artisan::call($command);
            } catch (\Throwable) {
                // storage:link puede fallar si el enlace ya existe o el hosting no permite symlinks
            }
        }

        // Se muestra una sola vez: desde ahora /install responde 404
        return $this->view(5, ['email' => strtolower($data['email'])]);
    }

    private function view(int $step, array $data = []): View
    {
        return view('installer.wizard', ['step' => $step, 'steps' => self::STEPS] + $data);
    }

    /**
     * Lo que depende del dominio sale de la dirección con la que se abrió /install: el .env de
     * partida (.env.example) trae valores de desarrollo (localhost, tunesa.app) que no sirven.
     *
     * @return array<string, string|bool>
     */
    public static function domainSettings(Request $request): array
    {
        $host = strtolower($request->getHost());
        $domain = preg_replace('/^www\./', '', $host);

        return [
            'APP_URL'                  => rtrim($request->root(), '/'),
            'APP_DOMAIN'               => $domain,
            'SANCTUM_STATEFUL_DOMAINS' => $domain === $host ? "$domain,www.$domain" : "$domain,$host",
            'SESSION_SECURE_COOKIE'    => $request->isSecure(),
        ];
    }

    private function validatedDatabase(Request $request): array
    {
        return $request->validate([
            'driver'   => ['required', 'in:mysql,mariadb'],
            'host'     => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:_\[\]]+$/'],
            'port'     => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-$]+$/'],
            'username' => ['required', 'string', 'max:80'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /** null si conecta; si no, el motivo para mostrar */
    private function tryConnect(array $data): ?string
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $data['host'], $data['port'], $data['database']);

        try {
            $pdo = new PDO($dsn, $data['username'], (string) ($data['password'] ?? ''), [
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            return match (true) {
                str_contains($e->getMessage(), '1045')  => 'Usuario o contraseña incorrectos.',
                str_contains($e->getMessage(), '1049')  => 'La base de datos no existe. Créala primero en tu hosting.',
                str_contains($e->getMessage(), '2002')  => 'No se pudo llegar al servidor. Revisa el host y el puerto.',
                default                                  => 'No se pudo conectar: ' . mb_substr($e->getMessage(), 0, 200),
            };
        }

        return null;
    }
}
