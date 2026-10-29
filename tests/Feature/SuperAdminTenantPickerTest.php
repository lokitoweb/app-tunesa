<?php

namespace Tests\Feature;

use App\Modules\HR\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Super Admin en "Todas las empresas": crear o editar pregunta "¿En qué
 * empresa?" (o usa la única activa) en lugar de un aviso que lo bloquea.
 */
class SuperAdminTenantPickerTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const SHIFT = [
        'name' => 'Turno Noche', 'type' => 'fixed', 'start_time' => '22:00', 'end_time' => '06:00',
        'grace_period_minutes' => 10, 'break_duration_minutes' => 60, 'working_days' => [1, 2, 3],
        'daily_hours' => 8, 'color' => '#0284C7', 'is_active' => 1,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_picker_lists_only_active_companies_and_opens_the_requested_screen(): void
    {
        $acme   = $this->makeTenant('acme', ['company_name' => 'Acme SAC']);
        $this->makeTenant('other', ['company_name' => 'Other SRL']);
        $this->makeTenant('dormida', ['company_name' => 'Dormida SA', 'status' => 'suspended']);
        $root = $this->makeUser(null, 'super_admin');

        $this->actingAs($root)->get('/employees/create')
            ->assertRedirect(route('admin.tenant.choose', ['next' => '/employees/create']));

        $this->get(route('admin.tenant.choose', ['next' => '/employees/create']))->assertOk()
            ->assertSee('¿En qué empresa?')->assertSee('Acme SAC')->assertSee('Other SRL')->assertDontSee('data-label="dormida sa', false)
            ->assertSee('value="/employees/create"', false)
            ->assertSee('href="' . route('employees.index') . '"', false);

        $this->post('/admin/switch-tenant', ['tenant_id' => $acme->id, 'next' => '/employees/create'])
            ->assertRedirect('/employees/create');
        $this->assertSame($acme->id, session('admin_tenant_id'));
        $this->get('/employees/create')->assertOk();
    }

    public function test_money_screens_never_add_up_companies_in_the_global_view(): void
    {
        $acme = $this->makeTenant('acme');
        $this->makeTenant('other');
        $root = $this->makeUser(null, 'super_admin');
        $range = '?from=2026-10-01&to=2026-10-31';

        // Saldos, totales y reportes son de una sola empresa: sin elegirla se pregunta, nunca se suman
        foreach (['/treasury', '/petty-cash', '/fixed-costs', '/cash-flow', '/finance-reports', "/finance-reports/scheduled{$range}",
            '/payroll-payments', '/inventory', '/fuel', '/purchases/by-product'] as $url) {
            $this->actingAs($root)->get($url)->assertRedirect(route('admin.tenant.choose', ['next' => $url]));
        }
        // Los listados sin montos siguen en la vista global
        $this->get('/suppliers')->assertOk();

        // Con la empresa elegida, la pantalla abre con sus datos
        $this->post('/admin/switch-tenant', ['tenant_id' => $acme->id, 'next' => '/treasury'])->assertRedirect('/treasury');
        $this->get('/treasury')->assertOk();
        $this->get('/payroll-payments')->assertOk();
    }

    public function test_next_never_leaves_the_panel(): void
    {
        $acme = $this->makeTenant('acme');
        $this->makeTenant('other');
        $root = $this->makeUser(null, 'super_admin');

        foreach (['https://evil.test/x', '//evil.test/x', '/\\evil.test', 'javascript:alert(1)'] as $next) {
            $location = $this->actingAs($root)->post('/admin/switch-tenant', ['tenant_id' => $acme->id, 'next' => $next])
                ->assertRedirect()->headers->get('Location');
            $this->assertStringStartsWith(url('/') . '/', $location, $next);
            $this->assertStringNotContainsString('evil', $location, $next);
        }
    }

    public function test_submitted_form_is_kept_and_saved_in_the_chosen_company(): void
    {
        $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $root  = $this->makeUser(null, 'super_admin');

        $this->actingAs($root)->post('/shifts', self::SHIFT)->assertStatus(409)
            ->assertSee('action="' . url('/shifts') . '"', false)
            ->assertSee('name="working_days[1]" value="2"', false)
            ->assertSee('value="Turno Noche"', false);
        $this->assertDatabaseMissing('shifts', ['name' => 'Turno Noche']);

        // El selector reenvía lo escrito con _tenant (el horario va a una agencia de esa empresa)
        $branch = $this->makeBranch($other);
        $this->post('/shifts', self::SHIFT + ['_tenant' => $other->id, 'branch_id' => $branch->id])->assertRedirect();
        $shift = Shift::withoutGlobalScopes()->where('name', 'Turno Noche')->sole();
        $this->assertSame($other->id, $shift->tenant_id);
        $this->assertSame($other->id, session('admin_tenant_id'), 'El panel queda en esa empresa');
    }

    public function test_inactive_company_in_tenant_field_is_ignored(): void
    {
        $this->makeTenant('acme');
        $this->makeTenant('other');
        $off  = $this->makeTenant('off', ['status' => 'suspended']);
        $root = $this->makeUser(null, 'super_admin');

        $this->actingAs($root)->post('/shifts', self::SHIFT + ['_tenant' => $off->id])->assertStatus(409);
        $this->assertDatabaseMissing('shifts', ['name' => 'Turno Noche']);
    }

    public function test_single_active_company_is_used_without_asking(): void
    {
        $acme = $this->makeTenant('acme');
        $this->makeTenant('off', ['status' => 'suspended']);
        $root = $this->makeUser(null, 'super_admin');

        $branch = $this->makeBranch($acme);
        $this->actingAs($root)->post('/shifts', self::SHIFT + ['branch_id' => $branch->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($acme->id, Shift::withoutGlobalScopes()->where('name', 'Turno Noche')->value('tenant_id'));
        $this->assertNull(session('admin_tenant_id'), 'Sigue en la vista global');

        $this->get(route('admin.tenant.choose', ['next' => '/shifts']))->assertRedirect('/shifts');
    }

    public function test_vue_and_json_requests_get_the_picker_url(): void
    {
        $this->makeTenant('acme');
        $this->makeTenant('other');
        $root = $this->makeUser(null, 'super_admin');
        $this->actingAs($root);

        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => '', 'Referer' => url('/shifts')])
            ->post('/shifts', self::SHIFT)
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('admin.tenant.choose', ['next' => '/shifts']));

        $this->flushHeaders()->postJson('/shifts', self::SHIFT)->assertStatus(409)
            ->assertJsonPath('tenant_required', true)
            ->assertJsonPath('choose_url', route('admin.tenant.choose', ['next' => '/shifts']));
    }

    public function test_only_super_admin_opens_the_picker(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');

        $this->actingAs($admin)->get(route('admin.tenant.choose'))->assertForbidden();
    }

    public function test_vue_screens_receive_company_status_for_the_picker(): void
    {
        $this->makeTenant('acme');
        $this->makeTenant('off', ['status' => 'suspended']);
        $root = $this->makeUser(null, 'super_admin');

        $tenants = collect($this->actingAs($root)->get('/shifts')->assertOk()->prop('tenants'));
        $this->assertEqualsCanonicalizing(['active', 'suspended'], $tenants->pluck('status')->all());
    }
}
