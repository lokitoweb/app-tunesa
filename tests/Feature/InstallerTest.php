<?php

namespace Tests\Feature;

use App\Installer\EnvWriter;
use App\Installer\InstallState;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Asistente de instalación: redirige mientras no hay Super Admin, crea la
 * cuenta y se bloquea; una instalación existente se detecta sola.
 */
class InstallerTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        $this->dir = sys_get_temp_dir() . '/installer-' . uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir . '/.env', "APP_NAME=Demo\nDB_CONNECTION=sqlite\n");
        $this->app->useEnvironmentPath($this->dir);

        config(['installer.enabled' => true, 'installer.lock_file' => $this->dir . '/installed.lock']);
        InstallState::forget();
    }

    protected function tearDown(): void
    {
        InstallState::forget();
        parent::tearDown();
        foreach (['.env', 'installed.lock'] as $file) {
            is_file($this->dir . '/' . $file) && unlink($this->dir . '/' . $file);
        }
        is_dir($this->dir) && rmdir($this->dir);
    }

    public function test_domain_settings_come_from_the_address_used_to_install(): void
    {
        $settings = fn (string $url) => \App\Installer\Http\InstallerController::domainSettings(\Illuminate\Http\Request::create($url));

        $this->assertSame([
            'APP_URL' => 'https://cliente.com.pe', 'APP_DOMAIN' => 'cliente.com.pe',
            'SANCTUM_STATEFUL_DOMAINS' => 'cliente.com.pe,www.cliente.com.pe', 'SESSION_SECURE_COOKIE' => true,
            'MAIL_FROM_ADDRESS' => 'no-reply@cliente.com.pe',
        ], $settings('https://cliente.com.pe/install/database'));

        $www = $settings('https://WWW.Cliente.com/install/database');
        $this->assertSame('cliente.com', $www['APP_DOMAIN']);
        $this->assertSame('cliente.com,www.cliente.com', $www['SANCTUM_STATEFUL_DOMAINS']);

        // Sin HTTPS la cookie no puede ser "secure" o no se podría iniciar sesión
        $this->assertFalse($settings('http://erp.cliente.com/install/database')['SESSION_SECURE_COOKIE']);

        (new EnvWriter($this->dir . '/.env'))->set($settings('https://cliente.com.pe/install'));
        $this->assertStringContainsString("SANCTUM_STATEFUL_DOMAINS='cliente.com.pe,www.cliente.com.pe'", file_get_contents($this->dir . '/.env'));
    }

    public function test_fresh_server_goes_to_the_wizard_and_api_answers_503(): void
    {
        $this->get('/login')->assertRedirect('/install');
        $this->getJson('/api/v1/app/config')->assertStatus(503)->assertJsonPath('code', 'NOT_INSTALLED');
        $this->get('/install')->assertOk()->assertSee('Requisitos del servidor')->assertSee('pdo_mysql');
        $this->get('/up')->assertOk();
    }

    public function test_admin_step_creates_super_admin_sets_platform_and_locks_the_wizard(): void
    {
        $this->get('/install/admin')->assertOk()->assertSee('Plataforma y Super Admin');

        $this->post('/install/admin', [
            'trade_name' => 'Mi ERP', 'login_email_domain' => '@MiDominio.com', 'timezone' => 'America/Lima',
            'first_name' => 'Ana', 'last_name' => 'Root', 'email' => 'root@midominio.com',
            'password' => 'Seguro12345', 'password_confirmation' => 'Seguro12345',
        ])->assertOk()->assertSee('Instalación completa');

        $root = User::where('email', 'root@midominio.com')->sole();
        $this->assertSame('super_admin', $root->role);
        $this->assertNull($root->tenant_id);
        $this->assertTrue(Hash::check('Seguro12345', $root->password));
        $this->assertSame('midominio.com', TenantSetting::forTenant(null)->loginEmailDomain());
        $this->assertSame('Mi ERP', TenantSetting::forTenant(null)->tradeName());
        $this->assertStringContainsString("APP_NAME='Mi ERP'", file_get_contents($this->dir . '/.env'));
        // El asistente queda apagado en el .env, no solo bloqueado por el archivo
        $this->assertStringContainsString('INSTALLER_ENABLED=false', file_get_contents($this->dir . '/.env'));
        $this->assertFileExists($this->dir . '/installed.lock');

        // Bloqueado: no se puede volver a instalar ni crear otro Super Admin
        InstallState::forget();
        $this->get('/install')->assertNotFound();
        $this->post('/install/admin', ['email' => 'otro@x.com'])->assertNotFound();
        $this->get('/login')->assertOk();
    }

    public function test_weak_password_and_bad_domain_are_rejected(): void
    {
        $this->post('/install/admin', [
            'trade_name' => 'X', 'login_email_domain' => 'no es dominio', 'timezone' => 'America/Lima',
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.com',
            'password' => 'corta', 'password_confirmation' => 'corta',
        ])->assertSessionHasErrors(['login_email_domain', 'password']);

        $this->assertSame(0, User::where('role', 'super_admin')->count());
    }

    public function test_existing_installation_is_detected_and_locked(): void
    {
        User::create([
            'tenant_id' => null, 'first_name' => 'S', 'last_name' => 'A', 'email' => 'sa@x.com',
            'password' => Hash::make('x'), 'role' => 'super_admin', 'status' => 'active',
        ]);

        $this->get('/install')->assertNotFound();
        $this->assertFileExists($this->dir . '/installed.lock');
    }

    public function test_database_test_validates_input_before_connecting(): void
    {
        $this->postJson('/install/database/test', ['driver' => 'mysql', 'host' => "evil\nhost", 'port' => 3306, 'database' => 'x;drop', 'username' => 'u'])
            ->assertStatus(422)->assertJsonValidationErrors(['host', 'database']);

        $this->postJson('/install/database/test', ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'nada', 'username' => 'u'])
            ->assertStatus(422)->assertJsonPath('ok', false);
    }

    public function test_env_writer_quotes_values_and_blocks_injection(): void
    {
        $env = new EnvWriter($this->dir . '/.env');
        $env->set(['DB_PASSWORD' => 'p@ss #1 ${X}', 'APP_DEBUG' => false, 'DB_HOST' => 'db.local']);
        $content = file_get_contents($this->dir . '/.env');

        $this->assertStringContainsString("DB_PASSWORD='p@ss #1 \${X}'", $content);
        $this->assertStringContainsString('APP_DEBUG=false', $content);
        $this->assertStringContainsString('DB_HOST=db.local', $content);
        $this->assertSame('"it\'s \\"q\\" \\$"', EnvWriter::quote('it\'s "q" $'));

        $this->expectException(\InvalidArgumentException::class);
        $env->set(['DB_PASSWORD' => "x\nAPP_KEY=robada"]);
    }
}
