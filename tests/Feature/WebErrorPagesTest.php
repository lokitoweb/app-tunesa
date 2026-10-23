<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

class WebErrorPagesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_404_page_renders_executive_template(): void
    {
        $response = $this->get('/non-existent-page-url-xyz');
        $response->assertStatus(404);
        $response->assertSee('Error 404', false);
        $response->assertSee('No encontramos esta página', false);
        $response->assertSee('Volver atrás', false); // un 404 ofrece volver; entrar queda como opción secundaria
        $response->assertSee('Ir al inicio de sesión', false);
    }

    public function test_403_page_renders_executive_template(): void
    {
        Route::middleware(['web'])->get('/_test/abort-403', fn () => abort(403, 'Permisos insuficientes para este módulo de auditoría.'));

        $response = $this->get('/_test/abort-403');
        $response->assertStatus(403);
        $response->assertSee('Error 403', false);
        $response->assertSee('Acceso no autorizado', false);
        $response->assertSee('Permisos insuficientes para este módulo de auditoría.', false);
    }

    public function test_500_page_renders_executive_template(): void
    {
        Route::middleware(['web'])->get('/_test/abort-500', fn () => abort(500, 'Error crítico controlado de prueba.'));

        $response = $this->get('/_test/abort-500');
        $response->assertStatus(500);
        $response->assertSee('Error 500', false);
        $response->assertSee('Algo salió mal de nuestro lado', false);
        $response->assertSee($response->headers->get('X-Request-Id'), false);
    }

    public function test_419_page_renders_executive_template(): void
    {
        Route::middleware(['web'])->get('/_test/abort-419', fn () => abort(419));

        $response = $this->get('/_test/abort-419');
        $response->assertStatus(419);
        $response->assertSee('Error 419', false);
        $response->assertSee('Tu sesión expiró', false);
        $response->assertSee('Iniciar sesión', false);
    }

    public function test_admin_user_calendar_redirects_gracefully_instead_of_404(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin', ['first_name' => 'Carlos', 'last_name' => 'Ramírez']);
        $hr    = $this->makeUser($acme, 'hr_manager', ['first_name' => 'Ana', 'last_name' => 'RRHH']);

        $response = $this->actingAs($hr)->get(route('attendance.calendar.person', $admin->id));
        $response->assertRedirect(route('employees.index'));
        $response->assertSessionHas('warning');
    }

    public function test_error_page_shows_one_logo_per_theme(): void
    {
        $html = $this->get('/non-existent-page-url-xyz')->assertNotFound()->getContent();

        // El logo claro se oculta en tema oscuro (has-dark) y solo entonces aparece el blanco
        $this->assertSame(1, preg_match_all('/class="brand-logo-light has-dark"/', $html));
        $this->assertSame(1, preg_match_all('/class="brand-logo-dark"/', $html));
    }
}
