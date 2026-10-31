<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Las páginas de acceso no se guardan en el navegador: al reabrirlo piden un token CSRF vigente */
class LoginNoStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_pages_are_not_stored_by_the_browser(): void
    {
        foreach (['/login', '/forgot-password'] as $uri) {
            $this->assertStringContainsString('no-store', (string) $this->get($uri)->assertOk()->headers->get('Cache-Control'), $uri);
        }
        $this->assertStringContainsString('no-store', (string) $this->getJson('/csrf-token')->headers->get('Cache-Control'));
    }
}
