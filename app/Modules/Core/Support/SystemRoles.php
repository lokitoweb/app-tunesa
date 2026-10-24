<?php

namespace App\Modules\Core\Support;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Sincroniza los roles y permisos del sistema con la matriz del código
 * (RolesAndPermissionsSeeder). Es idempotente y se ejecuta solo al terminar
 * `php artisan migrate`; los roles propios de cada empresa no se tocan.
 */
final class SystemRoles
{
    public static function sync(): bool
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('permission_role')) {
            return false;
        }

        try {
            (new RolesAndPermissionsSeeder())->run();
            Log::info('system_roles_synced', ['roles' => count(RolesAndPermissionsSeeder::ROLES), 'permissions' => count(RolesAndPermissionsSeeder::PERMISSIONS)]);

            return true;
        } catch (\Throwable $e) {
            // Nunca tumbar un despliegue por esto: queda en el log y se puede correr a mano
            report($e);

            return false;
        }
    }
}
