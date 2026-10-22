<?php

namespace App\Installer;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ¿Está instalada la plataforma? Sí si existe el archivo de bloqueo o si la
 * base ya tiene un Super Admin (instalaciones anteriores al asistente: se
 * escribe el bloqueo en ese momento y no se vuelve a consultar la base).
 */
final class InstallState
{
    private static ?bool $installed = null;

    public static function enabled(): bool
    {
        return (bool) config('installer.enabled', true);
    }

    public static function installed(): bool
    {
        if (! self::enabled()) {
            return true;
        }

        if (self::$installed !== null) {
            return self::$installed;
        }

        if (is_file(self::lockFile())) {
            return self::$installed = true;
        }

        if (self::databaseHasSuperAdmin()) {
            self::markInstalled('detected');

            return self::$installed = true;
        }

        return self::$installed = false;
    }

    public static function markInstalled(string $how = 'wizard'): void
    {
        $dir = dirname(self::lockFile());
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents(self::lockFile(), json_encode([
            'installed_at' => now()->toIso8601String(),
            'how'          => $how,
        ], JSON_PRETTY_PRINT));

        self::$installed = true;
    }

    public static function lockFile(): string
    {
        return (string) config('installer.lock_file', storage_path('app/installed.lock'));
    }

    /** Solo para pruebas: olvida el valor memorizado en este proceso */
    public static function forget(): void
    {
        self::$installed = null;
    }

    public static function databaseConnects(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function migrated(): bool
    {
        try {
            return Schema::hasTable('migrations') && Schema::hasTable('users') && Schema::hasTable('roles')
                && DB::table('roles')->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function databaseHasSuperAdmin(): bool
    {
        try {
            return Schema::hasTable('users') && DB::table('users')->where('role', 'super_admin')->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
