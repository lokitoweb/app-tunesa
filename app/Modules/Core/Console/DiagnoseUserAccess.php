<?php

namespace App\Modules\Core\Console;

use App\Modules\Core\Models\Role;
use App\Modules\Core\Models\User;
use App\Modules\ModuleRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;

/**
 * Explica por qué una persona ve "No tienes permiso para ver esta sección"
 * en la app o en el panel:
 *
 *   php artisan app:diagnose-user juan@empresa.com
 *   php artisan app:diagnose-user juan@empresa.com --fix
 *
 * Revisa: estado del usuario, empresa activa, módulos apagados para su
 * empresa y los permisos de su rol en la base contra la matriz de
 * RolesAndPermissionsSeeder. --fix vuelve a cargar los permisos de los roles
 * del sistema (idempotente). Los módulos apagados no se tocan: se encienden
 * en el panel (Empresas › Módulos), porque es una decisión de la empresa.
 */
class DiagnoseUserAccess extends Command
{
    protected $signature = 'app:diagnose-user {email : Correo con el que ingresa} {--fix : Recargar los permisos de los roles del sistema}';

    protected $description = 'Diagnostica por qué un usuario recibe "sin permiso" (rol, permisos, módulos y empresa)';

    /** Lo mínimo que usa la app del colaborador */
    private const APP_PERMISSIONS = [
        'attendance.mark'     => 'Marcar asistencia',
        'attendance.view_own' => 'Tarjeta de Inicio e historial',
        'shifts.view_own'     => 'Ver su turno',
        'leave.request'       => 'Pedir vacaciones y permisos',
        'payslips.view_own'   => 'Ver sus boletas',
    ];

    public function handle(): int
    {
        $user = User::withoutGlobalScopes()->with('tenant')->where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->components->error('No existe un usuario con ese correo.');

            return self::FAILURE;
        }

        $problems = 0;
        $this->components->info("{$user->full_name} · {$user->email}");
        $this->components->twoColumnDetail('Rol', $user->role);
        $this->components->twoColumnDetail('Empresa', $user->tenant?->company_name ?? '— (plataforma)');

        if ($user->status !== 'active') {
            $problems++;
            $this->components->error("El usuario está {$user->status}: actívalo en Colaboradores.");
        }

        if ($user->tenant && ! $user->tenant->isActive()) {
            $problems++;
            $this->components->error("La empresa está {$user->tenant->status}: la API responde TENANT_INACTIVE.");
        }

        // Módulos apagados para su empresa
        $off = array_values(array_filter(ModuleRegistry::names(), fn ($m) => ! ModuleRegistry::enabledFor($user->tenant, $m)));
        if ($off) {
            $labels = array_map(fn ($m) => ModuleRegistry::LABELS[$m] ?? $m, $off);
            $this->components->twoColumnDetail('Módulos apagados', implode(', ', $labels));
            if (in_array('Attendance', $off, true)) {
                $problems++;
                $this->components->error('El módulo Asistencia está apagado para esta empresa: la tarjeta de Inicio y la marcación responden MODULE_DISABLED. Enciéndelo en el panel: Empresas › (empresa) › Módulos.');
            }
        }

        // Permisos del rol: lo que hay en la base contra la matriz del sistema
        if ($user->isSuperAdmin()) {
            $this->components->info('Super Admin: tiene todos los permisos.');
        } else {
            $role = Role::query()->whereNull('tenant_id')->where('slug', $user->role)->with('permissions:id,slug')->first();
            $expected = isset(RolesAndPermissionsSeeder::ROLES[$user->role])
                ? RolesAndPermissionsSeeder::expand(RolesAndPermissionsSeeder::ROLES[$user->role][2])
                : null;

            if (! $role) {
                $problems++;
                $this->components->error("El rol \"{$user->role}\" no existe en la base: corre con --fix (o php artisan db:seed --class=RolesAndPermissionsSeeder).");
            } else {
                $actual = $role->permissions->pluck('slug')->all();
                $this->components->twoColumnDetail('Permisos del rol en la base', (string) count($actual));
                if ($expected !== null) {
                    $missing = array_values(array_diff($expected, $actual));
                    if ($missing) {
                        $problems++;
                        $this->components->error(count($missing) . ' permisos del rol no están cargados en la base (p. ej. ' . implode(', ', array_slice($missing, 0, 6)) . '). Corre con --fix.');
                    }
                }
            }

            $this->newLine();
            $this->line('  <options=bold>Permisos que usa la app</>');
            foreach (self::APP_PERMISSIONS as $slug => $label) {
                $has = $user->hasPermission($slug);
                $this->components->twoColumnDetail($label . " <fg=gray>({$slug})</>", $has ? '<fg=green>sí</>' : '<fg=red>NO</>');
            }
        }

        if ($this->option('fix')) {
            $this->newLine();
            $this->call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
            $this->call('cache:clear');
            $this->components->info('Permisos de los roles recargados. Cierra y vuelve a abrir la app (o desliza para actualizar Inicio).');

            return self::SUCCESS;
        }

        $this->newLine();
        if ($problems === 0) {
            $this->components->info('No se encontraron problemas de acceso para este usuario.');
        } else {
            $this->components->warn("{$problems} problema(s) encontrado(s).");
        }

        return $problems ? self::FAILURE : self::SUCCESS;
    }
}
