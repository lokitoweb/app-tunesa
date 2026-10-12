<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Monolito modular: cada carpeta de app/Modules registra sus rutas y
 * migraciones, y una empresa puede tener módulos apagados.
 */
class ModularArchitectureTest extends TestCase
{
    use RefreshDatabase, CreatesTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_every_module_registers_its_own_routes_and_migrations(): void
    {
        $this->assertSame(['Core', 'Attendance', 'Ethics', 'HR', 'Integrations', 'Onboarding', 'Payroll', 'Training'], ModuleRegistry::names());

        $byModule = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->getActionName(), 'App\\Modules\\'))
            ->groupBy(fn ($route) => $route->getAction('module'));

        foreach (ModuleRegistry::names() as $module) {
            $this->assertNotEmpty($byModule[$module] ?? [], "{$module} no registró rutas");
            // Ningún módulo sirve controladores de otro
            foreach ($byModule[$module] as $route) {
                $this->assertStringStartsWith("App\\Modules\\{$module}\\", $route->getActionName(), $route->uri());
            }
            $this->assertNotEmpty(glob(app_path("Modules/{$module}/Database/Migrations/*.php")), "{$module} sin migraciones");
        }

        // Las rutas de la API llevan prefijo api.: no pisan los nombres del panel (shifts.index...)
        foreach (['shifts.index' => '/shifts', 'employees.index' => '/employees', 'branches.index' => '/branches', 'payslips.index' => '/payslips'] as $name => $path) {
            $this->assertSame($path, route($name, [], false));
        }
        $this->assertSame('/api/admin/shifts', route('api.shifts.index', [], false));

        // Las migraciones de todos los módulos corrieron (una tabla de cada uno)
        foreach (['tenants', 'attendances', 'shifts', 'webhook_deliveries', 'payslips'] as $table) {
            $this->assertTrue(DB::getSchemaBuilder()->hasTable($table), $table);
        }
    }

    public function test_a_disabled_module_is_closed_for_that_tenant_only(): void
    {
        $acme   = $this->makeTenant('acme');
        $other  = $this->makeTenant('other');
        $admin  = $this->makeUser($acme, 'company_admin');
        $alien  = $this->makeUser($other, 'company_admin');
        $root   = $this->makeUser(null, 'super_admin');

        // El Super Admin apaga HR y Payroll para Acme desde el formulario de empresas
        $this->actingAs($root)->put("/companies/{$acme->id}", [
            'company_name' => $acme->company_name, 'slug' => $acme->slug, 'email' => 'contacto@acme.test',
            'plan' => 'trial', 'status' => 'active', 'timezone' => 'America/Lima',
            'primary_color' => '#1E40AF', 'secondary_color' => '#F59E0B',
            'modules_submitted' => '1', 'modules' => ['Attendance', 'Integrations'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(['Ethics', 'HR', 'Onboarding', 'Payroll', 'Training'], $acme->fresh()->setting('disabled_modules'));
        $this->assertFalse(ModuleRegistry::enabledFor($acme->fresh(), 'HR'));
        $this->assertTrue(ModuleRegistry::enabledFor($acme->fresh(), 'Core'));

        // Panel: las pantallas del módulo se cierran y salen del menú; el resto sigue igual
        $this->actingAs($admin->fresh())->get('/shifts')->assertForbidden();
        $this->get('/payslips')->assertForbidden();
        $this->get('/attendance')->assertOk();
        $labels = collect($this->get('/audit')->assertOk()->prop('nav'))->flatMap(fn ($s) => array_column($s['items'], 'label'));
        $this->assertNotContains('Turnos y horarios', $labels);
        $this->assertNotContains('Boletas de pago', $labels);
        $this->assertContains('Marcaciones y GPS', $labels);

        // API (app móvil): mismo cierre, con su código
        cache()->flush(); // ResolveTenant guarda la empresa 5 minutos
        $this->actingIn($admin, $acme)->getJson('/api/admin/shifts')->assertForbidden()->assertJsonPath('code', 'MODULE_DISABLED');
        $this->getJson('/api/attendance/today')->assertOk();

        // Otra empresa no se entera
        $this->actingAs($alien)->get('/shifts')->assertOk();

        // Editar la empresa sin tocar los módulos (p. ej. desde otra pantalla) no los cambia
        $this->actingAs($root)->put("/companies/{$acme->id}", [
            'company_name' => 'Acme 2', 'slug' => $acme->slug, 'email' => 'contacto@acme.test',
            'plan' => 'trial', 'status' => 'active', 'timezone' => 'America/Lima',
            'primary_color' => '#1E40AF', 'secondary_color' => '#F59E0B',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['Ethics', 'HR', 'Onboarding', 'Payroll', 'Training'], Tenant::find($acme->id)->setting('disabled_modules'));
    }

    public function test_moved_user_model_keeps_existing_tokens_and_notifications(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme, 'employee');
        $ana->createToken('app');
        DB::table('personal_access_tokens')->update(['tokenable_type' => 'App\\Models\\User']); // fila anterior al cambio
        $this->assertSame(0, $ana->tokens()->count());

        (require app_path('Modules/Core/Database/Migrations/2026_10_16_000001_point_morph_types_to_modules.php'))->up();

        $this->assertSame(1, $ana->tokens()->count());
        $this->assertSame(User::class, DB::table('personal_access_tokens')->value('tokenable_type'));
    }

    public function test_panel_responses_carry_security_headers(): void
    {
        $admin = $this->makeUser($this->makeTenant('acme'), 'company_admin');

        foreach ([$this->get('/login'), $this->actingAs($admin)->get('/dashboard'), $this->get('/shifts')] as $response) {
            $response->assertOk()
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        // Con HTTPS se anuncia HSTS; sin HTTPS no (rompería el desarrollo local)
        $this->assertFalse($this->get('/dashboard')->headers->has('Strict-Transport-Security'));
        $this->get('https://localhost/dashboard')->assertHeader('Strict-Transport-Security');
    }

    public function test_frontend_build_is_split_per_module_without_sourcemaps(): void
    {
        $config = file_get_contents(base_path('vite.config.js'));
        $this->assertStringContainsString('sourcemap: false', $config);
        $this->assertStringContainsString('manualChunks', $config);

        // Toda pantalla que un controlador pide con Inertia::render existe en su módulo
        foreach (glob(app_path('Modules/*/Controllers/Web/*.php')) as $file) {
            preg_match_all("/Inertia::render\\('([^']+)'/", file_get_contents($file), $matches);
            foreach ($matches[1] as $component) {
                $this->assertFileExists(resource_path("js/Modules/{$component}.vue"), basename($file));
                $this->assertStringStartsWith(basename(dirname($file, 3)) . '/Pages/', $component, 'La pantalla debe vivir en el módulo de su controlador');
            }
        }
    }
}
