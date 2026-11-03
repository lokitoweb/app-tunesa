<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Versión del panel web: la escribe scripts/deploy.sh (storage/app/version.json) y la muestra el menú del usuario.
 */
class WebVersionTest extends TestCase
{
    public function test_version_comes_from_the_deploy_file(): void
    {
        $file = storage_path('app/version.json');
        $previous = @file_get_contents($file);

        try {
            @unlink($file);
            $this->assertSame('Versión web dev', web_version()['label']);

            file_put_contents($file, '{"version":"1.445","commit":"f892da7","date":"2026-11-03"}');
            $this->assertSame(['1.445', 'f892da7', 'Versión web 1.445 · 03/11/2026'], [web_version()['version'], web_version()['commit'], web_version()['label']]);
            $this->assertStringContainsString('storage/app/version.json', file_get_contents(base_path('scripts/deploy.sh')));
        } finally {
            $previous === false ? @unlink($file) : file_put_contents($file, $previous);
        }
    }
}
