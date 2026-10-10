<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Empresas en el panel (Super Admin).
 */
class CompanyWebTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('public');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Constructora Andina', 'slug' => 'andina', 'email' => 'contacto@andina.pe',
            'custom_domain' => '', 'ruc' => '20123456789', 'phone' => '', 'address' => '',
            'plan' => 'basic', 'status' => 'active', 'timezone' => 'America/Lima',
            'primary_color' => '#0F766E', 'secondary_color' => '#F59E0B',
            'admin_first_name' => 'Carla', 'admin_last_name' => 'Ríos', 'admin_email' => 'carla@andina.pe',
            'admin_password' => 'Temporal123',
        ], $overrides);
    }

    public function test_super_admin_lists_and_creates_company_with_first_admin_in_global_view(): void
    {
        $admin = $this->makeUser(null, 'super_admin');
        $acme  = $this->makeTenant('acme');
        $this->makeUser($acme, 'employee');
        $this->makeUser($acme, 'hr_manager');

        $this->actingAs($admin)->get('/dashboard')->assertSee('Plataforma')->assertSee(route('companies.index'));
        $this->get('/companies')->assertOk()->assertSee('Acme')->assertSee('acme.')->assertSee('Activa');
        $this->get('/companies/create')->assertOk()->assertSee('Primer administrador');

        $this->post('/companies', $this->payload(['logo' => UploadedFile::fake()->image('logo.png', 120, 40)]))
            ->assertRedirect(route('companies.index'))->assertSessionHasNoErrors();

        $tenant = Tenant::where('slug', 'andina')->firstOrFail();
        $this->assertSame('#0F766E', $tenant->primary_color);
        $this->assertNotNull($tenant->logo_path);
        Storage::disk('public')->assertExists($tenant->logo_path);

        $first = User::where('email', 'carla@andina.pe')->firstOrFail();
        $this->assertSame($tenant->id, $first->tenant_id);
        $this->assertSame('company_admin', $first->role);
        $this->assertTrue($first->must_change_password);

        $this->get('/companies')->assertSee('Constructora Andina');
    }

    public function test_create_works_with_another_company_selected_and_validates_like_the_api(): void
    {
        $admin = $this->makeUser(null, 'super_admin');
        $acme  = $this->makeTenant('acme');

        $this->actingAs($admin)->post('/admin/switch-tenant', ['tenant_id' => $acme->id]);

        $this->post('/companies', $this->payload(['slug' => 'acme', 'admin_email' => 'x@y.pe']))->assertSessionHasErrors('slug');
        $this->post('/companies', $this->payload(['slug' => 'www']))->assertSessionHasErrors('slug');
        $this->post('/companies', $this->payload(['primary_color' => 'rojo']))->assertSessionHasErrors('primary_color');
        $this->post('/companies', $this->payload(['admin_password' => 'corta']))->assertSessionHasErrors('admin_password');

        $this->post('/companies', $this->payload())->assertSessionHasNoErrors();
        $this->assertSame(Tenant::where('slug', 'andina')->value('id'), User::where('email', 'carla@andina.pe')->value('tenant_id'));
    }

    public function test_super_admin_edits_and_suspends_a_company(): void
    {
        $admin = $this->makeUser(null, 'super_admin');
        $acme  = $this->makeTenant('acme');
        $this->makeUser($acme, 'company_admin', ['email' => 'jefe@acme.pe']);

        $this->actingAs($admin)->get("/companies/{$acme->id}/edit")->assertOk()->assertSee('jefe@acme.pe');

        $this->put("/companies/{$acme->id}", $this->payload([
            'company_name' => 'Acme Perú', 'slug' => 'acme', 'email' => 'acme@example.test',
            'status' => 'suspended', 'custom_domain' => 'asistencia.acme.pe', 'timezone' => 'America/Bogota',
        ]))->assertRedirect(route('companies.index'))->assertSessionHasNoErrors();

        $acme->refresh();
        $this->assertSame('Acme Perú', $acme->company_name);
        $this->assertSame('suspended', $acme->status);
        $this->assertSame('asistencia.acme.pe', $acme->custom_domain);
        $this->assertSame('America/Bogota', $acme->timezone);
    }

    public function test_only_super_admin_can_manage_companies(): void
    {
        $acme = $this->makeTenant('acme');
        $companyAdmin = $this->makeUser($acme, 'company_admin');

        $this->actingAs($companyAdmin)->get('/companies')->assertForbidden();
        $this->post('/companies', $this->payload())->assertForbidden();
        $this->get("/companies/{$acme->id}/edit")->assertForbidden();
        $this->get('/dashboard')->assertDontSee(route('companies.index'));
    }
}
