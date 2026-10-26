<?php

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\File;

/**
 * QA Ronda 2 · HQ-03: permisos que el seeder define y una base desplegada solo con
 * migraciones no tenía. Gerencia sin attendance.edit (no podía completar salidas) y
 * attendance.authorize_offshift inexistente (nadie salvo el super admin autorizaba
 * marcar fuera de turno).
 *
 * Solo agrega: crea el permiso si falta y lo asigna a los roles del sistema que lo
 * tienen en RolesAndPermissionsSeeder. Guarda lo que agregó en storage para que
 * down() quite exactamente eso y no permisos que ya existían.
 */
return new class extends Migration
{
    private const SLUGS = ['attendance.edit', 'attendance.authorize_offshift'];

    public static function logPath(): string
    {
        return storage_path('app/migrations/2026_11_01_000001_sync_attendance_correction_permissions.json');
    }

    public function up(): void
    {
        $added = ['permissions' => [], 'grants' => []];

        foreach (self::SLUGS as $slug) {
            [$name, $group] = RolesAndPermissionsSeeder::PERMISSIONS[$slug];
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $name, 'group' => $group]);
            if ($permission->wasRecentlyCreated) {
                $added['permissions'][] = $slug;
            }

            foreach (RolesAndPermissionsSeeder::ROLES as $roleSlug => [, , $grants]) {
                if (! in_array($slug, RolesAndPermissionsSeeder::expand($grants), true)) {
                    continue;
                }
                foreach (Role::whereNull('tenant_id')->where('slug', $roleSlug)->get() as $role) {
                    if ($role->permissions()->syncWithoutDetaching([$permission->id])['attached']) {
                        $added['grants'][] = [$roleSlug, $slug];
                    }
                }
            }
        }

        File::ensureDirectoryExists(dirname(self::logPath()));
        File::put(self::logPath(), json_encode($added, JSON_PRETTY_PRINT));
    }

    public function down(): void
    {
        if (! File::exists(self::logPath())) {
            return;
        }
        $added = json_decode(File::get(self::logPath()), true);

        foreach ($added['grants'] ?? [] as [$roleSlug, $slug]) {
            $permissionId = Permission::where('slug', $slug)->value('id');
            Role::whereNull('tenant_id')->where('slug', $roleSlug)->get()
                ->each(fn (Role $role) => $role->permissions()->detach($permissionId));
        }
        Permission::whereIn('slug', $added['permissions'] ?? [])->get()->each(function (Permission $p) {
            $p->roles()->detach();
            $p->delete();
        });

        File::delete(self::logPath());
    }
};
