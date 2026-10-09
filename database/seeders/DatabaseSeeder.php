<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * DatabaseSeeder — Datos iniciales para desarrollo y demostración.
 *
 * Crea:
 * - 1 Super Admin global
 * - 1 Tenant de demostración "Empresa Demo S.A."
 * - Company Admin, HR Manager, Supervisor y 3 Empleados
 * - 2 Sucursales con geocercas reales (Lima, Perú)
 * - 3 Turnos de trabajo (Mañana, Tarde, Noche)
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Roles y matriz de permisos (idempotente)
        $this->call(RolesAndPermissionsSeeder::class);

        // =====================================================================
        // 1. SUPER ADMIN (sin tenant)
        // =====================================================================
        $superAdmin = User::create([
            'tenant_id'  => null,
            'first_name' => 'Super',
            'last_name'  => 'Admin',
            'email'      => 'superadmin@tunesa.app',
            'password'   => Hash::make('SuperAdmin2024!'),
            'role'       => 'super_admin',
            'status'     => 'active',
        ]);

        $this->command->info("✅ Super Admin creado: {$superAdmin->email}");

        // =====================================================================
        // 2. TENANT DE DEMOSTRACIÓN
        // =====================================================================
        $tenant = Tenant::create([
            'slug'         => 'empresa-demo',
            'company_name' => 'Empresa Demo S.A.',
            'ruc'          => '20123456789',
            'email'        => 'admin@empresa-demo.com',
            'phone'        => '+51 1 234-5678',
            'address'      => 'Av. Javier Prado Este 2578, San Isidro, Lima',
            'plan'         => 'professional',
            'status'       => 'active',
            'timezone'     => 'America/Lima',
            'currency'     => 'PEN',
            'primary_color'   => '#1E40AF',
            'secondary_color' => '#F59E0B',
        ]);

        $this->command->info("✅ Tenant creado: {$tenant->company_name} (slug: {$tenant->slug})");

        // =====================================================================
        // 3. USUARIOS DEL TENANT
        // =====================================================================

        $companyAdmin = User::create([
            'tenant_id'  => $tenant->id,
            'first_name' => 'Carlos',
            'last_name'  => 'Ramírez',
            'email'      => 'admin@empresa-demo.com',
            'password'   => Hash::make('Admin2024!'),
            'role'       => 'company_admin',
            'status'     => 'active',
            'position'   => 'Gerente General',
            'hire_date'  => '2020-01-15',
        ]);

        $hrManager = User::create([
            'tenant_id'       => $tenant->id,
            'first_name'      => 'María',
            'last_name'       => 'González',
            'email'           => 'rrhh@empresa-demo.com',
            'password'        => Hash::make('RRHH2024!'),
            'role'            => 'hr_manager',
            'status'          => 'active',
            'position'        => 'Jefa de Recursos Humanos',
            'employee_code'   => 'EMP-001',
            'base_salary'     => 4500.00,
            'hire_date'       => '2021-03-01',
        ]);

        $supervisor = User::create([
            'tenant_id'       => $tenant->id,
            'first_name'      => 'Pedro',
            'last_name'       => 'Vásquez',
            'email'           => 'supervisor@empresa-demo.com',
            'password'        => Hash::make('Supervisor2024!'),
            'role'            => 'supervisor',
            'status'          => 'active',
            'position'        => 'Supervisor de Operaciones',
            'employee_code'   => 'EMP-002',
            'base_salary'     => 3200.00,
            'hire_date'       => '2021-06-15',
        ]);

        // Empleados
        $employees = [
            [
                'first_name'    => 'Ana',
                'last_name'     => 'Torres',
                'email'         => 'ana.torres@empresa-demo.com',
                'employee_code' => 'EMP-003',
                'base_salary'   => 2000.00,
                'position'      => 'Vendedora',
                'hire_date'     => '2022-01-10',
            ],
            [
                'first_name'    => 'Luis',
                'last_name'     => 'Mendoza',
                'email'         => 'luis.mendoza@empresa-demo.com',
                'employee_code' => 'EMP-004',
                'base_salary'   => 2200.00,
                'position'      => 'Almacenero',
                'hire_date'     => '2022-05-20',
            ],
            [
                'first_name'    => 'Rosa',
                'last_name'     => 'Huamán',
                'email'         => 'rosa.huaman@empresa-demo.com',
                'employee_code' => 'EMP-005',
                'base_salary'   => 1900.00,
                'position'      => 'Cajera',
                'hire_date'     => '2023-02-01',
            ],
        ];

        $employeeUsers = [];
        foreach ($employees as $emp) {
            $employeeUsers[] = User::create(array_merge($emp, [
                'tenant_id' => $tenant->id,
                'password'  => Hash::make('Employee2024!'),
                'role'      => 'employee',
                'status'    => 'active',
            ]));
        }

        $this->command->info("✅ Usuarios creados: 1 Company Admin, 1 HR Manager, 1 Supervisor, 3 Empleados");

        // =====================================================================
        // 4. SUCURSALES CON GEOCERCAS (Lima, Perú)
        // =====================================================================

        $branch1 = Branch::create([
            'tenant_id'              => $tenant->id,
            'name'                   => 'Sede Principal - San Isidro',
            'code'                   => 'SED-01',
            'address'                => 'Av. Javier Prado Este 2578',
            'city'                   => 'Lima',
            'country'                => 'Peru',
            'latitude'               => -12.09418,   // Coordenadas reales Lima
            'longitude'              => -77.03560,
            'allowed_radius_meters'  => 100,          // 100 metros de radio
            'is_active'              => true,
            'supervisor_id'          => $supervisor->id,
        ]);

        $branch2 = Branch::create([
            'tenant_id'              => $tenant->id,
            'name'                   => 'Sucursal Miraflores',
            'code'                   => 'SED-02',
            'address'                => 'Av. Larco 345, Miraflores',
            'city'                   => 'Lima',
            'country'                => 'Peru',
            'latitude'               => -12.12110,   // Av. Larco, Miraflores
            'longitude'              => -77.02970,
            'allowed_radius_meters'  => 50,
            'is_active'              => true,
            'supervisor_id'          => $supervisor->id,
        ]);

        $this->command->info("✅ Sucursales creadas: San Isidro (100m), Miraflores (50m)");

        // =====================================================================
        // 5. ASIGNAR EMPLEADOS A SUCURSALES
        // =====================================================================
        foreach ($employeeUsers as $idx => $emp) {
            $branch = $idx < 2 ? $branch1 : $branch2;
            $emp->branches()->attach($branch->id, [
                'is_primary'    => true,
                'assigned_from' => $emp->hire_date,
            ]);
        }

        $supervisor->branches()->attach($branch1->id, ['is_primary' => true, 'assigned_from' => $supervisor->hire_date]);
        $supervisor->branches()->attach($branch2->id, ['is_primary' => false, 'assigned_from' => $supervisor->hire_date]);

        // =====================================================================
        // 6. TURNOS DE TRABAJO
        // =====================================================================

        $shiftMorning = Shift::create([
            'tenant_id'              => $tenant->id,
            'name'                   => 'Turno Mañana',
            'code'                   => 'T-MAÑ',
            'start_time'             => '08:00:00',
            'end_time'               => '17:00:00',
            'crosses_midnight'       => false,
            'grace_period_minutes'   => 10,
            'break_allowed'          => true,
            'break_duration_minutes' => 60,
            'working_days'           => [1, 2, 3, 4, 5],  // Lun-Vie
            'daily_hours'            => 8.0,
            'color'                  => '#3B82F6',
            'is_active'              => true,
        ]);

        $shiftAfternoon = Shift::create([
            'tenant_id'              => $tenant->id,
            'name'                   => 'Turno Tarde',
            'code'                   => 'T-TAR',
            'start_time'             => '14:00:00',
            'end_time'               => '22:00:00',
            'crosses_midnight'       => false,
            'grace_period_minutes'   => 10,
            'break_allowed'          => true,
            'break_duration_minutes' => 30,
            'working_days'           => [1, 2, 3, 4, 5, 6],  // Lun-Sáb
            'daily_hours'            => 8.0,
            'color'                  => '#F59E0B',
            'is_active'              => true,
        ]);

        $shiftNight = Shift::create([
            'tenant_id'              => $tenant->id,
            'name'                   => 'Turno Noche',
            'code'                   => 'T-NOC',
            'start_time'             => '22:00:00',
            'end_time'               => '06:00:00',
            'crosses_midnight'       => true,
            'grace_period_minutes'   => 15,
            'break_allowed'          => true,
            'break_duration_minutes' => 30,
            'working_days'           => [0, 1, 2, 3, 4, 5, 6],  // Todos los días
            'daily_hours'            => 8.0,
            'color'                  => '#8B5CF6',
            'is_active'              => true,
        ]);

        // Turno rotativo 4x2 (4 días de trabajo, 2 de descanso)
        Shift::create([
            'tenant_id'              => $tenant->id,
            'name'                   => 'Rotativo 4x2',
            'code'                   => 'T-ROT',
            'type'                   => 'rotating',
            'start_time'             => '07:00:00',
            'end_time'               => '19:00:00',
            'crosses_midnight'       => false,
            'grace_period_minutes'   => 10,
            'break_allowed'          => true,
            'break_duration_minutes' => 60,
            'working_days'           => [0, 1, 2, 3, 4, 5, 6],
            'rotation_work_days'     => 4,
            'rotation_rest_days'     => 2,
            'daily_hours'            => 11.0,
            'color'                  => '#10B981',
            'is_active'              => true,
        ]);

        $this->command->info("✅ Turnos creados: Mañana, Tarde, Noche y Rotativo 4x2");

        // =====================================================================
        // 7. ASIGNAR TURNOS A EMPLEADOS
        // =====================================================================
        foreach ($employeeUsers as $idx => $emp) {
            $shift  = $idx === 2 ? $shiftAfternoon : $shiftMorning;
            $branch = $idx < 2 ? $branch1 : $branch2;

            \App\Models\EmployeeShift::create([
                'tenant_id'      => $tenant->id,
                'user_id'        => $emp->id,
                'shift_id'       => $shift->id,
                'branch_id'      => $branch->id,
                'effective_from' => $emp->hire_date,
                'effective_until'=> null,
                'assigned_by'    => $hrManager->id,
                'is_active'      => true,
            ]);
        }

        $this->command->info("✅ Turnos asignados a empleados");

        // =====================================================================
        // 6. LEGAJO, DATOS DE PAGO Y DOTACIÓN (Fase 3)
        // =====================================================================
        \App\Models\CompanyConfiguration::create(['tenant_id' => $tenant->id]);

        [$ana, $luis] = [$employeeUsers[0], $employeeUsers[1]];

        \App\Models\EmployeeProfile::create([
            'tenant_id' => $tenant->id, 'user_id' => $ana->id,
            'address' => 'Av. Arequipa 1234', 'district' => 'Lince',
            'emergency_contact_name' => 'Carmen Torres', 'emergency_contact_phone' => '987654321',
            'bank_name' => 'BCP', 'bank_account_type' => 'payroll', 'bank_account_currency' => 'PEN',
            'bank_account_number' => '19112345678012', 'interbank_code' => '00219111234567801254',
            'pension_system' => 'afp', 'pension_fund' => 'AFP Integra', 'pension_code' => '612345ATOEA1',
            'health_insurance' => 'essalud', 'labor_regime' => 'general',
            'cts_bank_name' => 'Interbank', 'cts_account_number' => '8983123456789', 'cts_currency' => 'PEN',
            'payment_info_status' => 'verified', 'payment_info_updated_at' => now(),
            'payment_verified_by' => $hrManager->id, 'payment_verified_at' => now(),
        ]);
        \App\Models\EmployeeProfile::create([
            'tenant_id' => $tenant->id, 'user_id' => $luis->id,
            'bank_name' => 'BBVA', 'bank_account_type' => 'savings', 'bank_account_currency' => 'PEN',
            'bank_account_number' => '001101230200123456', 'pension_system' => 'onp',
            'payment_info_status' => 'pending', 'payment_info_updated_at' => now(),
        ]);

        \App\Models\UniformSize::create(['tenant_id' => $tenant->id, 'user_id' => $ana->id, 'shirt_size' => 'S', 'pants_size' => '28', 'shoe_size' => '37', 'shoe_type' => 'safety']);
        \App\Models\UniformSize::create(['tenant_id' => $tenant->id, 'user_id' => $luis->id, 'shirt_size' => 'L', 'pants_size' => '34', 'shoe_size' => '42', 'shoe_type' => 'safety']);
        \App\Models\UniformDelivery::create(['tenant_id' => $tenant->id, 'user_id' => $ana->id, 'item' => 'shirt', 'size' => 'S', 'quantity' => 2, 'delivered_at' => now()->subMonth(), 'delivered_by' => $hrManager->id]);

        $this->command->info("✅ Legajo demo: datos de pago, tallas y una entrega");

        // =====================================================================
        // RESUMEN FINAL
        // =====================================================================
        $this->command->newLine();
        $this->command->table(
            ['Rol', 'Email', 'Contraseña'],
            [
                ['Super Admin',    'superadmin@tunesa.app',          'SuperAdmin2024!'],
                ['Company Admin',  'admin@empresa-demo.com',         'Admin2024!'],
                ['HR Manager',     'rrhh@empresa-demo.com',          'RRHH2024!'],
                ['Supervisor',     'supervisor@empresa-demo.com',     'Supervisor2024!'],
                ['Employee 1',     'ana.torres@empresa-demo.com',     'Employee2024!'],
                ['Employee 2',     'luis.mendoza@empresa-demo.com',   'Employee2024!'],
                ['Employee 3',     'rosa.huaman@empresa-demo.com',    'Employee2024!'],
            ]
        );
        $this->command->info("🚀 Seeder completado. Header para la API: X-Tenant-Slug: empresa-demo");
    }
}
