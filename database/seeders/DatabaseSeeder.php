<?php

namespace Database\Seeders;

use App\Modules\Core\Models\Branch;
use App\Modules\HR\Models\Shift;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * DatabaseSeeder — Datos iniciales para desarrollo y demostración.
 *
 * Crea:
 * - 1 Super Admin global
 * - 1 Tenant de demostración "Empresa Demo S.A."
 * - Company Admin, HR Manager, Gerencia, Contabilidad, Supervisor y 3 Empleados
 * - 2 Sucursales con geocercas reales (Lima, Perú)
 * - 3 Turnos de trabajo (Mañana, Tarde, Noche)
 *
 * En producción (APP_ENV=production) solo crea roles y el Super Admin, con
 * el correo y la contraseña de SEED_SUPERADMIN_EMAIL / SEED_SUPERADMIN_PASSWORD:
 * nunca la empresa demo ni contraseñas conocidas.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Roles y matriz de permisos (idempotente)
        $this->call(RolesAndPermissionsSeeder::class);

        if (app()->isProduction()) {
            $this->seedProductionSuperAdmin();

            return;
        }

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
            'primary_color'   => '#3C729E',
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

            \App\Modules\HR\Models\EmployeeShift::create([
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
        \App\Modules\Core\Models\CompanyConfiguration::create(['tenant_id' => $tenant->id]);

        [$ana, $luis] = [$employeeUsers[0], $employeeUsers[1]];

        \App\Modules\HR\Models\EmployeeProfile::create([
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
        \App\Modules\HR\Models\EmployeeProfile::create([
            'tenant_id' => $tenant->id, 'user_id' => $luis->id,
            'bank_name' => 'BBVA', 'bank_account_type' => 'savings', 'bank_account_currency' => 'PEN',
            'bank_account_number' => '001101230200123456', 'pension_system' => 'onp',
            'payment_info_status' => 'pending', 'payment_info_updated_at' => now(),
        ]);

        \App\Modules\HR\Models\UniformSize::create(['tenant_id' => $tenant->id, 'user_id' => $ana->id, 'shirt_size' => 'S', 'pants_size' => '28', 'shoe_size' => '37', 'shoe_type' => 'safety']);
        \App\Modules\HR\Models\UniformSize::create(['tenant_id' => $tenant->id, 'user_id' => $luis->id, 'shirt_size' => 'L', 'pants_size' => '34', 'shoe_size' => '42', 'shoe_type' => 'safety']);
        \App\Modules\HR\Models\UniformDelivery::create(['tenant_id' => $tenant->id, 'user_id' => $ana->id, 'item' => 'shirt', 'size' => 'S', 'quantity' => 2, 'delivered_at' => now()->subMonth(), 'delivered_by' => $hrManager->id]);

        $this->command->info("✅ Legajo demo: datos de pago, tallas y una entrega");

        // Capacitación demo publicada para todo el personal
        $course = \App\Modules\Training\Models\TrainingCourse::create([
            'tenant_id' => $tenant->id, 'title' => 'Inducción Tunesa', 'category' => 'induccion',
            'description' => 'Conoce la empresa, cómo marcar tu asistencia y a quién acudir.',
            'estimated_minutes' => 15, 'is_mandatory' => true, 'due_days' => 7, 'passing_score' => 70,
            'status' => 'published', 'published_at' => now(), 'created_by' => $hrManager->id,
        ]);
        $course->lessons()->create(['tenant_id' => $tenant->id, 'position' => 0, 'title' => 'Bienvenida', 'type' => 'text',
            'content' => "Bienvenido al equipo.\n\nMarca tu entrada y salida desde la app con tu selfie dentro de la sede. Si tienes un problema, habla con tu supervisor."]);
        $course->lessons()->create(['tenant_id' => $tenant->id, 'position' => 1, 'title' => 'Cómo marcar asistencia', 'type' => 'video',
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'duration_minutes' => 3]);
        $course->questions()->create(['tenant_id' => $tenant->id, 'position' => 0, 'question' => '¿Desde dónde marcas tu asistencia?',
            'options' => ['Desde la app, dentro de la sede', 'Por WhatsApp', 'No hace falta marcar'], 'correct_option' => 0]);
        $course->targets()->create(['tenant_id' => $tenant->id, 'target_type' => 'all']);
        app(\App\Modules\Training\Services\TrainingEnrollmentService::class)->syncCourse($course->fresh('targets'));

        $this->command->info("✅ Capacitación demo: Inducción Tunesa (todo el personal)");

        // =====================================================================
        // CUMPLEAÑOS Y BENEFICIOS DEMO (fechas relativas a hoy: siempre hay algo que ver)
        // =====================================================================
        $today = now($tenant->timezone ?? config('app.timezone'));
        $birthdays = [
            [$employeeUsers[0], $today->copy()->subYears(28)],                  // Ana: hoy
            [$employeeUsers[1], $today->copy()->subYears(31)->addDays(3)],      // Luis: en 3 días
            [$employeeUsers[2], $today->copy()->subYears(25)->addDays(12)],     // Rosa: en 12 días
            [$supervisor, $today->copy()->subYears(35)->subDays(4)],            // Supervisor: hace poco
            [$hrManager, $today->copy()->subYears(40)->addMonths(2)],
        ];
        foreach ($birthdays as [$person, $date]) {
            $person->update(['birth_date' => $date->toDateString()]);
        }
        // Regalos animados que Ana ya recibió hoy (sin aviso push en el seed)
        foreach ([[$employeeUsers[1], 'crown'], [$employeeUsers[2], 'lion'], [$supervisor, 'galaxy']] as [$sender, $gift]) {
            \App\Modules\Birthdays\Models\BirthdayGift::create([
                'tenant_id' => $tenant->id, 'user_id' => $employeeUsers[0]->id, 'sender_id' => $sender->id,
                'gift_type' => $gift, 'date' => $today->toDateString(),
            ]);
        }

        $categories = [];
        foreach (\App\Modules\Benefits\Models\BenefitCategory::DEFAULTS as $i => [$name, $icon, $color]) {
            $categories[$name] = \App\Modules\Benefits\Models\BenefitCategory::create(['tenant_id' => $tenant->id, 'name' => $name, 'icon' => $icon, 'color' => $color, 'position' => $i]);
        }
        $demoBenefits = [
            ['Salud y bienestar', 'Gimnasio con 30% de descuento', 'Smart Fit', '30% dscto', 'TUNESA30', true],
            ['Alimentación', '2x1 en almuerzos los viernes', 'La Lucha Sanguchería', '2x1', null, false],
            ['Educación', 'Inglés con beca parcial', 'ICPNA', '40% beca', null, false],
        ];
        foreach ($demoBenefits as [$category, $title, $partner, $highlight, $code, $featured]) {
            $benefit = \App\Modules\Benefits\Models\Benefit::create([
                'tenant_id' => $tenant->id, 'category_id' => $categories[$category]->id, 'title' => $title, 'partner' => $partner,
                'highlight' => $highlight, 'promo_code' => $code, 'summary' => "Beneficio exclusivo para el personal de {$tenant->company_name}.",
                'how_to_use' => 'Muestra tu fotocheck y menciona el convenio con tu empresa.', 'is_featured' => $featured,
                'valid_until' => $today->copy()->addMonths(6)->toDateString(), 'status' => 'published', 'published_at' => now(), 'created_by' => $hrManager->id,
            ]);
            $benefit->syncAudience([['type' => 'all']]);
        }

        $this->command->info("✅ Cumpleaños y beneficios demo");

        // Gerencia y Contabilidad (paneles de solo lectura / planilla)
        foreach ([
            ['manager', 'Jorge', 'Salas', 'gerencia@empresa-demo.com', 'Gerencia2024!', 'Gerente de Operaciones', 'EMP-010'],
            ['accountant', 'Lucía', 'Paredes', 'contabilidad@empresa-demo.com', 'Contabilidad2024!', 'Contadora', 'EMP-011'],
        ] as [$role, $first, $last, $email, $password, $position, $code]) {
            $user = User::firstOrCreate(['email' => $email], [
                'tenant_id' => $tenant->id, 'first_name' => $first, 'last_name' => $last, 'password' => Hash::make($password),
                'role' => $role, 'status' => 'active', 'position' => $position, 'employee_code' => $code, 'hire_date' => '2022-02-01',
            ]);
            $user->branches()->syncWithoutDetaching([$branch1->id => ['is_primary' => true, 'assigned_from' => '2022-02-01']]);
        }

        // =====================================================================
        // FIRMA APP DEMO: Gerencia autoriza pagos; una OC programada espera su firma
        // =====================================================================
        $manager = User::where('email', 'gerencia@empresa-demo.com')->first();
        \App\Modules\Finance\Models\Signatory::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $manager->id],
            ['position' => 'Gerente de Operaciones', 'can_authorize' => true, 'is_active' => true],
        );
        $supplier = \App\Modules\Finance\Models\Supplier::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'ruc' => '20100047218'],
            ['name' => 'Llantas del Norte S.A.C.', 'is_active' => true],
        );
        $tenant->refresh();
        [$number, $code] = \App\Modules\Finance\Models\DocumentSequence::next($tenant, 'RQ', '001', (int) $today->year);
        [, $order] = \App\Modules\Finance\Models\DocumentSequence::next($tenant, 'OC', '001', (int) $today->year);
        $requisition = \App\Modules\Finance\Models\Requisition::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'series' => '001', 'number' => $number, 'code' => $code, 'year' => (int) $today->year,
            'requested_at' => $today, 'detail' => 'Llantas para la flota', 'requested_by' => 'Rosa Huamán', 'classification' => 'REPUESTOS',
            'priority' => 'NORMAL', 'stage' => 'ordered', 'ordered_at' => $today, 'supplier_id' => $supplier->id, 'amount' => 1250, 'amount_payable' => 1250,
            'currency' => 'PEN', 'installments' => 1, 'purchase_order_number' => $order, 'invoice_number' => 'F001-000123',
            'payment_terms' => 'CONTADO', 'payment_method' => 'TRANSFERENCIA', 'payment_origin' => 'TRANSFERENCIA',
        ]);
        \App\Modules\Finance\Models\PaymentInstallment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'requisition_id' => $requisition->id, 'installment' => 1, 'due_date' => $today->copy()->addDays(5)->toDateString(),
            'amount' => 1250, 'status' => 'pending', 'scheduled_for' => $today->toDateString(), 'scheduled_by' => 'Lucía Paredes',
        ]);
        $this->command->info("✅ Firma APP demo: {$order} programada, la autoriza gerencia@empresa-demo.com");

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
                ['Gerencia',       'gerencia@empresa-demo.com',      'Gerencia2024!'],
                ['Contabilidad',   'contabilidad@empresa-demo.com',  'Contabilidad2024!'],
                ['Supervisor',     'supervisor@empresa-demo.com',     'Supervisor2024!'],
                ['Employee 1',     'ana.torres@empresa-demo.com',     'Employee2024!'],
                ['Employee 2',     'luis.mendoza@empresa-demo.com',   'Employee2024!'],
                ['Employee 3',     'rosa.huaman@empresa-demo.com',    'Employee2024!'],
            ]
        );
        $this->command->info("🚀 Seeder completado. Header para la API: X-Tenant-Slug: empresa-demo");
    }

    /** Producción: Super Admin con credenciales del entorno, sin datos demo */
    private function seedProductionSuperAdmin(): void
    {
        $email    = config('app.seed_superadmin.email');
        $password = (string) config('app.seed_superadmin.password');

        if (! $email || strlen($password) < 12) {
            throw new RuntimeException('Define SEED_SUPERADMIN_EMAIL y SEED_SUPERADMIN_PASSWORD (12+ caracteres) en el .env antes de sembrar producción.');
        }

        User::firstOrCreate(['email' => $email, 'tenant_id' => null], [
            'first_name'           => 'Super',
            'last_name'            => 'Admin',
            'password'             => Hash::make($password),
            'role'                 => 'super_admin',
            'status'               => 'active',
            'must_change_password' => true,
        ]);

        $this->command->info("✅ Super Admin de producción: {$email}. Borra SEED_SUPERADMIN_PASSWORD del .env.");
    }
}
