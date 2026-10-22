<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * En producción se corre `php artisan config:cache`: desde ahí .env no se carga y env() fuera de
 * config/ devuelve null (o su valor por defecto). Todo valor del .env debe pasar por un archivo de config/.
 */
class NoEnvOutsideConfigTest extends TestCase
{
    public function test_env_is_only_called_from_config_files(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (['app', 'bootstrap', 'database', 'routes', 'resources/views'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php' || str_starts_with($file->getPathname(), "{$root}/bootstrap/cache")) {
                    continue;
                }
                foreach (file($file->getPathname()) as $n => $line) {
                    if (preg_match('/(?<![\w>:$])env\(/', $line)) {
                        $offenders[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($n + 1);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'Usa config() en lugar de env() fuera de config/: ' . implode(', ', $offenders));
    }
}
