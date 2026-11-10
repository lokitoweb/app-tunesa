<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_fails_with_debug_and_insecure_url(): void
    {
        config(['app.debug' => true, 'app.url' => 'http://example.com', 'session.secure' => false]);

        $this->artisan('app:check-production')
            ->expectsOutputToContain('APP_DEBUG')
            ->expectsOutputToContain('https://')
            ->assertExitCode(1);
    }

    public function test_passes_with_secure_configuration(): void
    {
        config([
            'app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://api.tunesa.app',
            'session.secure' => true, 'integrations.allow_private_urls' => false,
        ]);

        $this->artisan('app:check-production')->assertExitCode(0);
    }

    public function test_warns_when_the_scheduler_cron_is_not_running(): void
    {
        $this->artisan('app:check-production')->expectsOutputToContain('server-setup.sh');

        $this->artisan('schedule:run');   // el heartbeat del scheduler deja la señal

        $this->artisan('app:check-production')->doesntExpectOutputToContain('server-setup.sh');
    }

    public function test_api_responses_carry_security_headers(): void
    {
        $this->getJson('/api/auth/me')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_local_disk_files_are_not_served_by_url(): void
    {
        $this->get('/storage/payslips/1/x.pdf')->assertNotFound();
    }
    public function test_detects_secrets_reused_from_the_public_git_history(): void
    {
        $check = app(\App\Modules\Core\Console\CheckProductionReadiness::class);
        $leakedEnv = "APP_ENV=local
APP_KEY=base64:filtrada123
SUPERADMIN_PASSWORD=\"Vieja#Clave2026\"
";
        $root = \App\Modules\Core\Models\User::create(['first_name' => 'Root', 'last_name' => 'Admin', 'email' => 'root@example.test',
            'password' => 'Otra#Clave2026', 'role' => 'super_admin', 'status' => 'active']);

        // Nada coincide: sin errores ni advertencias
        config(['app.key' => 'base64:otra-clave']);
        $this->assertSame([[], []], $check->leakedSecrets(fn () => $leakedEnv));

        // Misma APP_KEY y misma contraseña que el .env del historial: dos errores, sin mostrar los valores
        config(['app.key' => 'base64:filtrada123']);
        $root->forceFill(['password' => 'Vieja#Clave2026'])->save();
        [$errors] = $check->leakedSecrets(fn () => $leakedEnv);
        $this->assertCount(2, $errors);
        $this->assertStringNotContainsString('filtrada123', implode(' ', $errors));
        $this->assertStringNotContainsString('Vieja#Clave2026', implode(' ', $errors));

        // Sin git en el servidor: lo avisa en vez de callar
        $this->assertSame(1, count($check->leakedSecrets(fn () => null)[1]));
    }
}
