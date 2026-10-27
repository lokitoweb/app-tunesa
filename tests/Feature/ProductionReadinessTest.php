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
}
