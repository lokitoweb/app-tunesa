<?php

namespace Tests\Feature;

use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Services\ServiceSuspension;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Servicio suspendido en toda la instalación (el cliente que compró el sistema no pagó): nadie de ninguna
 * empresa entra a la web ni a la app y ven «Servicio suspendido»; solo el Super Admin, con su enlace, entra a reactivarlo.
 */
class PlatformServiceSuspensionTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function tearDown(): void
    {
        // Nunca dejar el entorno local suspendido, aunque la prueba falle
        if (app()->maintenanceMode()->active()) {
            Artisan::call('up');
        }
        File::delete(ServiceSuspension::markerPath());
        parent::tearDown();
    }

    public function test_the_owner_suspends_everything_and_only_he_can_enter_to_reactivate(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $owner = $this->makeUser(null, 'super_admin');
        $admin = $this->makeUser($acme, 'company_admin');
        $worker = $this->makeUser($acme);

        // Nadie más puede apagar la plataforma, y hace falta escribir SUSPENDER
        $this->actingAs($admin)->post(route('platform.service.suspend'), ['confirm' => 'SUSPENDER'])->assertForbidden();
        $this->actingAs($owner)->post(route('platform.service.suspend'), ['confirm' => 'si'])->assertSessionHasErrors('confirm');
        $this->assertFalse(ServiceSuspension::active());

        $link = $this->actingAs($owner)->post(route('platform.service.suspend'), ['confirm' => 'SUSPENDER', 'message' => 'Servicio suspendido por falta de pago.'])
            ->assertRedirect()->headers->get('Location');
        $this->assertTrue(ServiceSuspension::active());
        $this->assertTrue(AuditLog::where('action', 'platform.suspended')->exists());

        // Web: la página de login (y cualquier otra) dice «Servicio suspendido»
        $this->app['auth']->forgetGuards();
        $this->get(route('login'))->assertStatus(503)->assertSee('Servicio suspendido')->assertSee('Servicio suspendido por falta de pago.');
        $this->post(route('login'), ['email' => $admin->email, 'password' => 'Secret123'])->assertStatus(503);

        // App: login y cualquier acción, con el aviso que la app muestra tal cual
        $this->withHeaders(['X-Tenant-Slug' => 'acme'])->postJson('/api/auth/login', ['email' => $worker->email, 'password' => 'Secret123', 'device_name' => 'tel'])
            ->assertStatus(503)->assertJsonPath('code', 'SERVICE_SUSPENDED')->assertJsonPath('message', 'Servicio suspendido por falta de pago.');

        // El dueño entra con su enlace (cookie de Laravel) y ve la plataforma suspendida para reactivarla
        $secret = ltrim(parse_url($link, PHP_URL_PATH), '/');
        $this->get('/' . $secret)->assertRedirect('/');
        $bypass = MaintenanceModeBypassCookie::create($secret);
        $this->withUnencryptedCookie('laravel_maintenance', $bypass->getValue())
            ->actingAs($owner)->get(route('companies.index'))->assertOk()->assertSee('SUSPENDIDO')->assertSee($link);

        $this->withUnencryptedCookie('laravel_maintenance', $bypass->getValue())
            ->actingAs($owner)->post(route('platform.service.resume'))->assertRedirect(route('companies.index'));
        $this->assertFalse(ServiceSuspension::active());
        $this->assertFalse(File::exists(ServiceSuspension::markerPath()));

        // Reactivado: todos entran de nuevo
        $this->app['auth']->forgetGuards();
        $this->get(route('login'))->assertOk();
    }
}
