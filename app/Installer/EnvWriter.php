<?php

namespace App\Installer;

use InvalidArgumentException;

/**
 * Escribe claves en el .env sin tocar el resto del archivo. Los valores se
 * citan para que espacios, # o $ no rompan el archivo, y se rechazan saltos
 * de línea (inyección de variables nuevas).
 */
final class EnvWriter
{
    public function __construct(private readonly string $path) {}

    public static function forApp(): self
    {
        return new self(app()->environmentFilePath());
    }

    /** Crea el .env desde la plantilla de producción (o la de desarrollo, si falta) si aún no existe */
    public function ensureExists(): void
    {
        if (is_file($this->path)) {
            return;
        }

        $dir = dirname($this->path) . DIRECTORY_SEPARATOR;
        $example = is_file($dir . '.env.production.example') ? $dir . '.env.production.example' : $dir . '.env.example';
        if (! @copy($example, $this->path) && ! @touch($this->path)) {
            throw new \RuntimeException('No se pudo crear el archivo .env. Revisa los permisos de la carpeta del proyecto.');
        }
    }

    /** @param array<string, string|int|bool|null> $values */
    public function set(array $values): void
    {
        $this->ensureExists();
        $content = (string) file_get_contents($this->path);

        foreach ($values as $key => $value) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                throw new InvalidArgumentException("Clave de entorno inválida: {$key}");
            }
            $line = $key . '=' . self::quote($value);
            $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

            $content = preg_match($pattern, $content)
                ? preg_replace_callback($pattern, fn () => $line, $content, 1)
                : rtrim($content, "\r\n") . PHP_EOL . $line . PHP_EOL;
        }

        if (file_put_contents($this->path, $content, LOCK_EX) === false) {
            throw new \RuntimeException('No se pudo escribir el archivo .env. Revisa sus permisos.');
        }
    }

    public static function quote(string|int|bool|null $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = (string) $value;
        if (preg_match('/[\r\n\0]/', $value)) {
            throw new InvalidArgumentException('El valor no puede tener saltos de línea.');
        }
        if ($value === '' ) {
            return '';
        }
        if (preg_match('/^[A-Za-z0-9_.:\/@+-]+$/', $value)) {
            return $value;
        }
        // Comillas simples: literal, sin interpolar ${...}
        if (! str_contains($value, "'")) {
            return "'{$value}'";
        }

        return '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value) . '"';
    }
}
