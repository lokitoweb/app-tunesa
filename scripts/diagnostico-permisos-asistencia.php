<?php

/**
 * Diagnóstico de SOLO LECTURA del candado "No tienes permiso para ver esta sección"
 * en "Mis asistencias" de la app. No escribe nada en la base.
 *
 *   cd /var/www/tunesa && php scripts/diagnostico-permisos-asistencia.php [correo-del-colaborador]
 *   php scripts/diagnostico-permisos-asistencia.php --reparar   # SOLO agrega a los roles del sistema los
 *       permisos que les faltan según RolesAndPermissionsSeeder; nunca quita ninguno (no usa sync)
 *
 * La pantalla llama a /api/attendance/history, /api/schedule y /api/my-branches; cualquier 403
 * muestra el candado. Revisa las causas posibles en el orden en que la API las evalúa.
 */

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\ModuleRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$needed = ['attendance.view_own', 'shifts.view_own'];
$line = fn (string $t) => print($t . PHP_EOL);
$repair = in_array('--reparar', $argv, true);
$argv = array_values(array_filter($argv, fn ($a) => $a !== '--reparar'));

if ($repair) {
    DB::transaction(function () use ($line) {
        foreach (RolesAndPermissionsSeeder::PERMISSIONS as $slug => [$name, $group]) {
            Permission::firstOrCreate(['slug' => $slug], ['name' => $name, 'group' => $group]);
        }
        foreach (RolesAndPermissionsSeeder::ROLES as $slug => [$name, $description, $grants]) {
            $role = Role::firstOrCreate(['tenant_id' => null, 'slug' => $slug], ['name' => $name, 'description' => $description, 'is_system' => true]);
            $ids = Permission::whereIn('slug', RolesAndPermissionsSeeder::expand($grants))->pluck('id');
            $added = $role->permissions()->syncWithoutDetaching($ids)['attached'];
            $line(sprintf('  reparado %-14s +%d permisos', $slug, count($added)));
        }
    });
    $line('Listo (solo se agregaron permisos). Vuelve a correr sin --reparar para comprobar.');
    exit(0);
}

$line('== 1. Roles de los colaboradores (users.role) ==');
foreach (User::withoutGlobalScopes()->selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role') as $role => $total) {
    $global = Role::whereNull('tenant_id')->where('slug', $role)->first();
    $has = $global ? $global->permissions()->whereIn('slug', $needed)->pluck('slug')->all() : [];
    $missing = array_diff($needed, $has);
    $line(sprintf('  %-16s %5d usuarios | rol global: %-3s | falta: %s', $role, $total, $global ? 'sí' : 'NO', $missing ? implode(', ', $missing) : '—'));
}

$line('');
$line('== 2. Permisos esperados vs. reales por rol del sistema ==');
foreach (RolesAndPermissionsSeeder::ROLES as $slug => [, , $grants]) {
    $expected = RolesAndPermissionsSeeder::expand($grants);
    $role = Role::whereNull('tenant_id')->where('slug', $slug)->first();
    $actual = $role ? $role->permissions()->pluck('slug')->all() : [];
    $missing = array_values(array_diff($expected, $actual));
    $line(sprintf('  %-14s esperados=%2d reales=%2d faltan=%s', $slug, count($expected), count($actual), $missing ? implode(', ', $missing) : '—'));
}
$line('  Permisos en la base: ' . Permission::count() . ' | en el seeder: ' . count(RolesAndPermissionsSeeder::PERMISSIONS));

$line('');
$line('== 3. Empresas: estado y módulos apagados ==');
foreach (Tenant::all() as $t) {
    $off = (array) $t->setting('disabled_modules', []);
    $line(sprintf('  %-22s estado=%-9s Attendance=%s HR=%s apagados=[%s]', $t->slug, $t->status, ModuleRegistry::enabledFor($t, 'Attendance') ? 'on' : 'OFF',
        ModuleRegistry::enabledFor($t, 'HR') ? 'on' : 'OFF', implode(',', $off)));
}

if ($email = $argv[1] ?? null) {
    $line('');
    $line("== 4. Colaborador {$email} ==");
    $u = User::withoutGlobalScopes()->where('email', $email)->first();
    if (! $u) {
        $line('  No existe.');
    } else {
        $line(sprintf('  rol=%s estado=%s tenant=%s debe_cambiar_clave=%s', $u->role, $u->status, $u->tenant?->slug ?? 'NINGUNO', $u->must_change_password ? 'sí' : 'no'));
        foreach ($needed as $p) {
            $line(sprintf('  %-22s %s', $p, $u->hasPermission($p) ? 'OK' : 'NO TIENE'));
        }
    }
}

$line('');
$line('== 5. Últimos 403 de la API en el log (código de cada uno) ==');
$log = storage_path('logs/laravel.log');
$codes = [];
if (is_file($log)) {
    foreach (array_slice(file($log), -20000) as $row) {
        if (preg_match('/(INSUFFICIENT_PERMISSION|MODULE_DISABLED|TENANT_SCOPE_VIOLATION|TENANT_INACTIVE|USER_NO_TENANT)/', $row, $m)) {
            $codes[$m[1]] = ($codes[$m[1]] ?? 0) + 1;
        }
    }
}
$line($codes ? '  ' . json_encode($codes) : '  (sin 403 registrados en el log; los 403 de permisos no siempre se registran)');
