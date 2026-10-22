<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * 419 al iniciar sesión tras la expiración por inactividad (22/10/2026):
 * el login con un token caducado vuelve al formulario en vez de mostrar un
 * error, el token se puede renovar, y las páginas de error son útiles y no
 * filtran la depuración fuera de la máquina local.
 */
class SessionExpiryLoginTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        // En las pruebas Laravel no valida CSRF: aquí sí, como en el navegador
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    public function test_login_with_expired_token_returns_to_login_keeping_the_email(): void
    {
        $response = $this->post('/login', [
            '_token'   => 'token-de-una-sesion-que-ya-expiro',
            'email'    => 'ana@example.test',
            'password' => 'Secret123',
        ]);

        $response->assertStatus(303)->assertRedirect(route('login'));
        $response->assertSessionHas('info', fn ($m) => str_contains($m, 'contraseña otra vez'));
        $this->assertSame('ana@example.test', session()->getOldInput('email'));
        $this->assertNull(session()->getOldInput('password'));
        $this->assertGuest();
    }

    public function test_login_works_with_a_refreshed_token_after_expiry(): void
    {
        $user = $this->makeUser($this->makeTenant('acme'), 'hr_manager', ['email' => 'rrhh@example.test']);

        // La página de login pide un token vigente antes de enviar
        $token = $this->get('/csrf-token')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->json('token');

        $this->assertSame(session()->token(), $token);

        $this->post('/login', ['_token' => $token, 'email' => 'rrhh@example.test', 'password' => 'Secret123'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_expired_form_of_a_logged_user_goes_back_without_sensitive_fields(): void
    {
        $user = $this->makeUser($this->makeTenant('acme'), 'hr_manager');

        $this->actingAs($user)
            ->from('/employees')
            ->post('/logout', ['_token' => 'caducado', 'password' => 'x'])
            ->assertStatus(303)
            ->assertRedirect('/employees')
            ->assertSessionHas('info');

        $this->assertNull(session()->getOldInput('password'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_expired_token_never_redirects_to_another_site(): void
    {
        $user = $this->makeUser($this->makeTenant('acme'), 'hr_manager');

        $this->actingAs($user)
            ->withHeader('Referer', 'https://evil.example/phish')
            ->post('/logout', ['_token' => 'caducado'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_ajax_request_with_expired_token_gets_json_and_a_fresh_token(): void
    {
        $this->postJson('/logout', [], ['X-CSRF-TOKEN' => 'caducado'])
            ->assertStatus(419)
            ->assertJsonPath('code', 'CSRF_EXPIRED')
            ->assertJsonStructure(['csrf_token']);
    }

    public function test_every_response_carries_a_reference_code(): void
    {
        $ref = $this->get('/login')->assertOk()->headers->get('X-Request-Id');

        $this->assertMatchesRegularExpression('/^[0-9A-F]{8}$/', $ref);
    }

    public function test_error_pages_show_the_logged_reference_and_guest_actions(): void
    {
        $response = $this->get('/esta-ruta-no-existe')->assertNotFound();
        $ref = $response->headers->get('X-Request-Id');

        $response->assertSee($ref)->assertSee('Iniciar sesión')->assertDontSee('Volver al Dashboard');
    }

    public function test_debug_page_is_hidden_behind_the_tunnel(): void
    {
        config(['app.debug' => true]);
        Route::middleware('web')->get('/_prueba/falla', fn () => throw new \RuntimeException('detalle-interno-secreto'));

        // Directo desde la máquina: depuración visible
        $this->get('/_prueba/falla')->assertStatus(500)->assertSee('detalle-interno-secreto');

        // Por Cloudflare/proxy: página 500 limpia, sin detalles ni SQL
        config(['app.debug' => true]);
        $this->withHeader('CF-Connecting-IP', '190.0.0.1')
            ->get('/_prueba/falla')
            ->assertStatus(500)
            ->assertDontSee('detalle-interno-secreto')
            ->assertSee('Algo salió mal de nuestro lado');
    }

    public function test_method_not_allowed_has_a_friendly_page(): void
    {
        $this->get('/keep-alive')->assertStatus(405)->assertSee('Esta acción no se puede abrir así');
    }

    public function test_sqlite_waits_briefly_for_locks_instead_of_hanging(): void
    {
        $this->assertSame(5000, (int) DB::selectOne('pragma busy_timeout')->timeout);
    }
}
