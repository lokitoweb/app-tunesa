<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Empresa suspendida (p. ej. por falta de pago): nadie entra al panel, a Mi espacio ni a la app, y el login
 * dice claramente que el acceso de la empresa está suspendido. Al reactivarla, todos vuelven a entrar.
 */
class TenantSuspensionTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_suspended_company_cannot_enter_anywhere_and_the_login_says_why(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme', ['company_name' => 'Tunesa Expres']);
        $admin = $this->makeUser($acme, 'company_admin', ['email' => 'admin@acme.test']);
        $worker = $this->makeUser($acme, 'employee', ['email' => 'ana@acme.test']);
        $message = 'El acceso de Tunesa Expres está suspendido. Nadie de la empresa puede ingresar al sistema ni a la app. Comunícate con el administrador de tu empresa.';

        // Ya dentro del panel cuando la suspenden: en el siguiente clic sale con el aviso
        $this->actingAs($admin)->get('/dashboard')->assertOk();
        $acme->update(['status' => 'suspended']);
        $this->actingAs($admin->fresh()); // cada petición real vuelve a leer la empresa de la base
        $this->get('/dashboard')->assertRedirect(route('login'))->assertSessionHasErrors(['email' => $message]);
        $this->assertGuest();

        // Login web: no abre la sesión y dice el motivo
        $this->post(route('login'), ['email' => 'admin@acme.test', 'password' => 'Secret123'])
            ->assertRedirect(route('login'))->assertSessionHasErrors(['email' => $message]);
        $this->assertGuest();

        // App (Android e iOS): login rechazado con el código que la app traduce a su aviso
        $this->withHeaders(['X-Tenant-Slug' => 'acme'])->postJson('/api/auth/login', ['email' => 'ana@acme.test', 'password' => 'Secret123', 'device_name' => 'tel'])
            ->assertStatus(403)->assertJsonPath('code', 'TENANT_INACTIVE')->assertJsonPath('message', $message);
        // Y la app que ya tenía sesión abierta tampoco puede seguir
        $this->actingIn($worker, $acme)->getJson('/api/attendance/today')->assertStatus(403)->assertJsonPath('code', 'TENANT_INACTIVE');
    }

    public function test_after_paying_the_company_is_reactivated_and_everyone_enters_again(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme', ['status' => 'suspended']);
        $admin = $this->makeUser($acme, 'company_admin', ['email' => 'admin@acme.test']);

        $acme->update(['status' => 'active']);
        $this->post(route('login'), ['email' => 'admin@acme.test', 'password' => 'Secret123'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin);
    }
}