<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Las notas de desarrollo van en comentarios Blade ({{-- --}}), que no llegan
 * al navegador. Un comentario HTML, CSS o JS se ve en "Ver código fuente".
 */
class NoVisibleCommentsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_no_view_has_html_comments(): void
    {
        $found = [];
        foreach ((new Finder)->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
            if (str_contains($file->getContents(), '<!--')) {
                $found[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $found, 'Usa {{-- --}} en lugar de <!-- -->');
    }

    public function test_public_pages_ship_without_comments(): void
    {
        $this->seedRoles();

        foreach (['/login', '/forgot-password', '/non-existent-page-url-xyz'] as $uri) {
            $html = (string) $this->get($uri)->getContent();

            $this->assertStringNotContainsString('<!--', $html, $uri);
            $this->assertStringNotContainsString('/*', $html, $uri);
            $this->assertDoesNotMatchRegularExpression('#^\s*//#m', $html, $uri);
        }
    }
}
