<?php

namespace Database\Seeders;

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Illuminate\Database\Seeder;

/**
 * RolesAndPermissionsSeeder
 *
 * Crea los 5 roles del sistema y su matriz de permisos. Es idempotente:
 * se puede volver a ejecutar tras agregar permisos nuevos.
 *
 *   php artisan db:seed --class=RolesAndPermissionsSeeder
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /** slug => [nombre, grupo] */
    public const PERMISSIONS = [
        // Plataforma
        'tenants.manage'         => ['Administrar empresas (plataforma)', 'tenants'],
        'tenants.branding'       => ['Editar marca blanca de la empresa', 'tenants'],
        'roles.view'             => ['Ver roles y permisos', 'roles'],

        // Personas y estructura
        'users.view'             => ['Ver colaboradores', 'users'],
        'users.manage'           => ['Crear y editar colaboradores', 'users'],
        'branches.view'          => ['Ver sucursales y geocercas', 'branches'],
        'branches.manage'        => ['Crear y editar sucursales y geocercas', 'branches'],

        // Turnos
        'shifts.view_own'        => ['Ver mis turnos', 'shifts'],
        'shifts.view'            => ['Ver turnos de la empresa', 'shifts'],
        'shifts.manage'          => ['Crear y editar turnos', 'shifts'],
        'shifts.assign'          => ['Asignar turnos a colaboradores', 'shifts'],

        // Asistencia
        'attendance.mark'        => ['Marcar asistencia (selfie + GPS)', 'attendance'],
        'attendance.view_own'    => ['Ver mi historial de asistencia', 'attendance'],
        'attendance.view_team'   => ['Ver asistencia de mi equipo', 'attendance'],
        'attendance.view_all'    => ['Ver asistencia de toda la empresa', 'attendance'],
        'attendance.review'      => ['Aprobar o rechazar justificaciones', 'attendance'],
        'attendance.edit'        => ['Corregir marcaciones a mano (con motivo)', 'attendance'],

        // Dashboard y reportes
        'dashboard.view'         => ['Ver dashboard y mapa en tiempo real', 'dashboard'],
        'reports.view'           => ['Ver reportes', 'reports'],
        'reports.export'         => ['Exportar reportes', 'reports'],

        // Solicitudes
        'leave.request'          => ['Solicitar vacaciones, permisos y licencias', 'leave'],
        'leave.approve_team'     => ['Aprobar solicitudes de mi equipo', 'leave'],
        'leave.approve_all'      => ['Aprobar solicitudes de toda la empresa', 'leave'],

        // Nómina
        'payslips.view_own'      => ['Ver y descargar mis boletas', 'payslips'],
        'payslips.manage'        => ['Subir, publicar y eliminar boletas', 'payslips'],
        'payroll.export'         => ['Exportar consolidado para planilla (incluye cuentas)', 'payslips'],

        // Legajo, ficha laboral y dotación (Fase 3)
        'profile.manage_own'      => ['Gestionar mi legajo, datos de pago y tallas', 'profiles'],
        'profiles.manage'         => ['Editar fichas laborales (régimen, CTS, cuentas)', 'profiles'],
        'profiles.view_sensitive' => ['Ver números de cuenta completos', 'profiles'],
        'documents.review'        => ['Revisar y aprobar documentos del legajo', 'profiles'],
        'uniforms.manage'         => ['Gestionar tallas y entregas de dotación', 'profiles'],
        'company.configure'       => ['Configurar qué ve el colaborador en la app', 'tenants'],
        'audit.view'              => ['Ver la bitácora de auditoría', 'tenants'],

        // Capacitaciones
        'training.view_own'       => ['Ver y completar mis capacitaciones', 'training'],
        'training.manage'         => ['Crear capacitaciones, asignarlas y ver el avance', 'training'],
        'training.unlock'         => ['Dar intentos extra en cuestionarios bloqueados', 'training'],

        // Incorporación (onboarding)
        'onboarding.review'       => ['Revisar y aprobar la incorporación de colaboradores', 'onboarding'],
        'onboarding.manage'       => ['Configurar las etapas de incorporación', 'onboarding'],

        // Canal ético
        'ethics.investigate'      => ['Ver e investigar las denuncias del canal ético', 'ethics'],
    ];

    /** slug del rol => [nombre, descripción, permisos ('*' = todos)] */
    public const ROLES = [
        Role::SUPER_ADMIN => [
            'Super Admin',
            'Operador de la plataforma. Acceso a todas las empresas.',
            ['*'],
        ],
        Role::COMPANY_ADMIN => [
            'Administrador de Empresa',
            'Dueño de la cuenta de la empresa. Acceso total dentro de su empresa.',
            ['*', '-tenants.manage'],
        ],
        Role::HR_MANAGER => [
            'Recursos Humanos',
            'Gestiona colaboradores, turnos, solicitudes y nómina.',
            ['*', '-tenants.manage', '-tenants.branding', '-company.configure', '-audit.view'],
        ],
        Role::SUPERVISOR => [
            'Supervisor',
            'Supervisa la asistencia y solicitudes de su equipo.',
            [
                'users.view', 'branches.view', 'shifts.view', 'shifts.view_own',
                'attendance.mark', 'attendance.view_own', 'attendance.view_team', 'attendance.edit',
                'dashboard.view', 'reports.view',
                'leave.request', 'leave.approve_team',
                'payslips.view_own', 'profile.manage_own', 'training.view_own',
            ],
        ],
        Role::EMPLOYEE => [
            'Colaborador',
            'Marca asistencia, consulta turnos, solicitudes y boletas.',
            [
                'shifts.view_own', 'attendance.mark', 'attendance.view_own',
                'leave.request', 'payslips.view_own', 'profile.manage_own', 'training.view_own',
            ],
        ],
    ];

    public function run(): void
    {
        $permissionIds = [];
        foreach (self::PERMISSIONS as $slug => [$name, $group]) {
            $permissionIds[$slug] = Permission::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'group' => $group],
            )->id;
        }

        foreach (self::ROLES as $slug => [$name, $description, $grants]) {
            $role = Role::updateOrCreate(
                ['tenant_id' => null, 'slug' => $slug],
                ['name' => $name, 'description' => $description, 'is_system' => true],
            );

            $role->permissions()->sync(
                array_values(array_intersect_key($permissionIds, array_flip(self::expand($grants))))
            );
        }

        $this->command?->info('✅ Roles y permisos: ' . count(self::ROLES) . ' roles, ' . count(self::PERMISSIONS) . ' permisos');
    }

    /**
     * Expande '*' (todos) y '-slug' (quitar) a una lista de slugs.
     *
     * @return array<int, string>
     */
    public static function expand(array $grants): array
    {
        $slugs = in_array('*', $grants, true) ? array_keys(self::PERMISSIONS) : [];

        foreach ($grants as $grant) {
            if ($grant === '*') {
                continue;
            }
            if (str_starts_with($grant, '-')) {
                $slugs = array_diff($slugs, [substr($grant, 1)]);
            } else {
                $slugs[] = $grant;
            }
        }

        return array_values(array_unique($slugs));
    }
}
