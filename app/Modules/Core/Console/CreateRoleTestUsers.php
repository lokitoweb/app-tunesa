<?php

namespace App\Modules\Core\Console;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Un usuario de prueba por cada rol de la empresa (Administrador, RRHH,
 * Gerencia, Contabilidad, Supervisor y Colaborador) para revisar qué ve cada
 * uno en el panel y en la app.
 *
 *   php artisan app:role-test-users empresa-demo
 *
 * Es idempotente: si el correo ya existe no lo duplica ni le cambia la
 * contraseña. Las contraseñas nuevas se generan y se muestran una sola vez.
 * En producción pide --force.
 */
class CreateRoleTestUsers extends Command
{
    protected $signature = 'app:role-test-users
        {tenant? : Slug de la empresa (por defecto la primera)}
        {--domain= : Dominio de los correos (por defecto el de la empresa)}
        {--force : Permitir en producción}';

    protected $description = 'Crea (sin duplicar) un usuario de prueba por cada rol de la empresa';

    /** rol => [usuario del correo, nombres, apellidos, cargo] */
    public const USERS = [
        Role::COMPANY_ADMIN => ['admin.prueba', 'Prueba', 'Administrador', 'Administrador de la empresa'],
        Role::HR_MANAGER    => ['rrhh.prueba', 'Prueba', 'Recursos Humanos', 'Analista de RR. HH.'],
        Role::MANAGER       => ['gerencia.prueba', 'Prueba', 'Gerencia', 'Gerente de operaciones'],
        Role::ACCOUNTANT    => ['contabilidad.prueba', 'Prueba', 'Contabilidad', 'Contador'],
        Role::LOGISTICS     => ['logistica.prueba', 'Prueba', 'Logística', 'Asistente de logística'],
        Role::LOGISTICS     => ['logistica.prueba', 'Prueba', 'Logística', 'Asistente de logística'],
        Role::SUPERVISOR    => ['supervisor.prueba', 'Prueba', 'Supervisor', 'Supervisor de sede'],
        Role::EMPLOYEE      => ['colaborador.prueba', 'Prueba', 'Colaborador', 'Colaborador'],
    ];

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->components->error('Estás en producción: usa --force si de verdad quieres crear usuarios de prueba.');

            return self::FAILURE;
        }

        $tenant = $this->argument('tenant')
            ? Tenant::where('slug', $this->argument('tenant'))->first()
            : Tenant::orderBy('id')->first();
        if (! $tenant) {
            $this->components->error('No encontré la empresa. Revisa el slug (php artisan tinker → Tenant::pluck(\'slug\')).');

            return self::FAILURE;
        }

        $domain = $this->option('domain') ?: (Str::after((string) $tenant->email, '@') ?: "{$tenant->slug}.test");
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('id')->first();
        $rows   = [];

        foreach (self::USERS as $role => [$local, $first, $last, $position]) {
            $email = "{$local}@{$domain}";
            $user  = User::withoutGlobalScopes()->where('email', $email)->first();

            if ($user) {
                $rows[] = [Role::query()->whereNull('tenant_id')->where('slug', $role)->value('name') ?? $role, $email, '(ya existía, sin cambios)'];

                continue;
            }

            $password = Str::password(12, symbols: false);
            $user = User::withoutGlobalScopes()->create([
                'tenant_id'            => $tenant->id,
                'first_name'           => $first,
                'last_name'            => $last,
                'email'                => $email,
                'password'             => Hash::make($password),
                'role'                 => $role,
                'status'               => 'active',
                'position'             => $position,
                'hire_date'            => now()->toDateString(),
                'must_change_password' => false,
            ]);
            if ($branch) {
                $user->branches()->attach($branch->id, ['is_primary' => true, 'assigned_from' => now()->toDateString()]);
            }
            $rows[] = [Role::query()->whereNull('tenant_id')->where('slug', $role)->value('name') ?? $role, $email, $password];
        }

        $this->components->info("Usuarios de prueba de {$tenant->company_name}" . ($branch ? " (sede: {$branch->name})" : ''));
        $this->table(['Rol', 'Correo', 'Contraseña'], $rows);
        $this->components->warn('Guarda las contraseñas ahora: no se vuelven a mostrar. Desactiva estos usuarios antes de salir a producción.');

        return self::SUCCESS;
    }
}
