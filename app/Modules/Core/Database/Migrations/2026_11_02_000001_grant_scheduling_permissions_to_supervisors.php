<?php

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\File;

/**
 * QA Ronda 2 · reglas vigentes 6 y 11: el supervisor programa los horarios de su agencia
 * (permiso nuevo shifts.program, que también tienen RR. HH. y administración) y autoriza
 * marcar en días sin horario a su equipo (attendance.authorize_offshift).
 *
 * Solo agrega: crea el permiso si falta y lo asigna a los roles del sistema que lo
 * tienen en RolesAndPermissionsSeeder. Guarda lo que agregó en storage para que
 * down() quite exactamente eso y no permisos que ya existían.
 */
return new class extends Migration
{
    private const SLUGS = ['shifts.program', 'attendance.authorize_offshift'];

    public static function logPath(): string
    {
        return storage_path('app/migrations/2026_11_02_000001_grant_scheduling_permissions_to_supervisors.json');
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
