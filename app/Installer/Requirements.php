<?php

namespace App\Installer;

/**
 * Paso 1 del asistente: versión de PHP, extensiones y carpetas con escritura.
 */
final class Requirements
{
    /** @return array{php: array, extensions: list<array>, folders: list<array>, ok: bool} */
    public static function check(): array
    {
        $minPhp = (string) config('installer.min_php', '8.2.0');
        $php = [
            'required' => $minPhp,
            'current'  => PHP_VERSION,
            'ok'       => version_compare(PHP_VERSION, $minPhp, '>='),
        ];

        $extensions = array_map(fn (string $ext) => [
            'name' => $ext,
            'ok'   => extension_loaded($ext),
        ], (array) config('installer.extensions', []));

        $folders = array_map(fn (string $dir) => [
            'name' => $dir,
            'ok'   => is_dir(base_path($dir)) && is_writable(base_path($dir)),
        ], (array) config('installer.writable', []));

        $envPath = app()->environmentFilePath();
        $folders[] = [
            'name' => '.env',
            'ok'   => is_file($envPath) ? is_writable($envPath) : is_writable(dirname($envPath)),
        ];

        $ok = $php['ok']
            && ! in_array(false, array_column($extensions, 'ok'), true)
            && ! in_array(false, array_column($folders, 'ok'), true);

        return compact('php', 'extensions', 'folders', 'ok');
    }
}
