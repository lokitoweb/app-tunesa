<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Renderers de bootstrap/app.php para la API: Laravel convierte
 * ModelNotFoundException y AuthorizationException en excepciones HTTP antes
 * de llamarlos; aun así la respuesta lleva code NOT_FOUND / UNAUTHORIZED.
 */
class ApiExceptionRenderingTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_find_or_fail_on_a_missing_id_returns_not_found_code(): void
    {
        $acme = $this->makeTenant('acme');
        $emp  = $this->makeUser($acme);

        $this->actingIn($emp, $acme)->getJson('/api/leave-requests/999999')
            ->assertStatus(404)
            ->assertExactJson([
                'success' => false,
                'message' => 'El recurso solicitado no fue encontrado.',
                'code'    => 'NOT_FOUND',
            ]);
    }

    public function test_authorization_failure_returns_unauthorized_code(): void
    {
        $acme = $this->makeTenant('acme');
        $emp  = $this->makeUser($acme);

        // Gate::authorize lanza AuthorizationException (no hay rutas de la API que lo usen todavía)
        Route::middleware(['api', 'auth:sanctum'])->get('api/_test/forbidden', function () {
            Gate::authorize('payslips.manage');
        });
        Route::middleware('api')->get('api/_test/policy', fn () => throw new AuthorizationException('Denied.'));

        $this->actingIn($emp, $acme)->getJson('/api/_test/forbidden')
            ->assertStatus(403)
            ->assertExactJson([
                'success' => false,
                'message' => 'No tienes permiso para esta acción.',
                'code'    => 'UNAUTHORIZED',
            ]);

        $this->getJson('/api/_test/policy')->assertStatus(403)->assertJsonPath('code', 'UNAUTHORIZED');
    }

    public function test_web_routes_keep_the_default_html_responses(): void
    {
        Route::middleware('web')->get('_test/policy', fn () => throw new AuthorizationException());

        $this->get('/_test/policy')->assertStatus(403)->assertDontSee('UNAUTHORIZED');
    }
}
