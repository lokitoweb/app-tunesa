<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** push:diagnose señala el eslabón que falla y nunca muestra secretos */
class DiagnosePushCommandTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_without_firebase_credentials_it_points_to_the_fix_and_fails(): void
    {
        $acme = $this->makeTenant('acme', ['company_name' => 'Acme SAC']);
        $ana = $this->makeUser($acme, 'employee', ['email' => 'ana@acme.test']);

        $this->artisan('push:diagnose', ['--user' => 'ana@acme.test'])
            ->expectsOutputToContain('Cuenta de servicio cargada y válida')
            ->expectsOutputToContain('Marca y app › Integraciones')
            ->expectsOutputToContain('Acme SAC: celulares registrados — 0 de 1')
            ->expectsOutputToContain('Celular registrado')
            ->assertFailed();

        $this->assertNull($ana->fresh()->fcm_token);
    }
}
