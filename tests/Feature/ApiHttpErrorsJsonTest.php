<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Errores HTTP 4xx de la API: siempre JSON limpio (success/message/code),
 * también con APP_DEBUG=true, nunca la página de excepción de Laravel.
 */
class ApiHttpErrorsJsonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => true]);
    }

    public function test_get_on_the_webhook_receiver_says_it_is_active(): void
    {
        $this->get('/api/v1/webhooks/receiver')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Receptor de webhooks activo. Envía los eventos por POST.',
            ]);
    }

    public function test_wrong_method_returns_clean_json_405(): void
    {
        $this->put('/api/v1/webhooks/receiver')
            ->assertStatus(405)
            ->assertHeader('Allow')
            ->assertExactJson([
                'success' => false,
                'message' => 'Método no permitido para esta ruta.',
                'code'    => 'METHOD_NOT_ALLOWED',
            ]);
    }

    public function test_unknown_api_route_returns_clean_json_404_even_from_a_browser(): void
    {
        $response = $this->get('/api/no-existe', ['Accept' => 'text/html']);

        $response->assertStatus(404)
            ->assertExactJson([
                'success' => false,
                'message' => 'La ruta solicitada no existe.',
                'code'    => 'NOT_FOUND',
            ]);
        $this->assertStringNotContainsString('Symfony', $response->getContent());
    }

    public function test_abort_keeps_its_own_message(): void
    {
        Route::middleware('api')->get('api/_test/abort', fn () => abort(403, 'Este módulo no está activo.'));

        $this->getJson('/api/_test/abort')
            ->assertStatus(403)
            ->assertExactJson([
                'success' => false,
                'message' => 'Este módulo no está activo.',
                'code'    => 'FORBIDDEN',
            ]);
    }

    public function test_web_routes_keep_the_default_html_responses(): void
    {
        $this->get('/no-existe-en-el-panel')->assertStatus(404)->assertDontSee('NOT_FOUND');
    }
}
