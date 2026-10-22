<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Core\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Correcciones de la auditoría del 22/10/2026 sobre el rediseño local:
 * KPIs de reportes agregados en SQL, cierre de sesión solo por POST,
 * dominio y marca sin valores fijos.
 */
class LocalChangesHardeningTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_report_kpis_are_aggregated_per_tenant_and_respect_supervisor_scope(): void
    {
        $acme  = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $sup   = $this->makeUser($acme, 'supervisor');
        $hr    = $this->makeUser($acme, 'hr_manager');
        $mine  = $this->makeBranch($acme, ['supervisor_id' => $sup->id]);
        $away  = $this->makeBranch($acme);
        $ana   = $this->makeUser($acme, 'employee', ['first_name' => 'Ana']);
        $beto  = $this->makeUser($acme, 'employee', ['first_name' => 'Beto']);
        $ana->branches()->attach($mine->id, ['is_primary' => true]);
        $beto->branches()->attach($away->id, ['is_primary' => true]);
        $foreign = $this->makeUser($other, 'employee');

        $day = today()->toDateString();
        Attendance::create(['tenant_id' => $acme->id, 'user_id' => $ana->id, 'branch_id' => $mine->id, 'work_date' => $day,
            'clock_in_at' => now(), 'status' => 'present', 'worked_hours' => 8, 'overtime_hours' => 1]);
        Attendance::create(['tenant_id' => $acme->id, 'user_id' => $beto->id, 'branch_id' => $away->id, 'work_date' => $day,
            'clock_in_at' => now(), 'status' => 'late', 'late_minutes' => 12, 'worked_hours' => 7.5]);
        Attendance::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'user_id' => $foreign->id, 'branch_id' => $this->makeBranch($other)->id,
            'work_date' => $day, 'clock_in_at' => now(), 'status' => 'present', 'worked_hours' => 9]);

        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

        $this->actingAs($hr)->withHeaders($ajax)->get('/reports?preset=today')->assertOk()
            ->assertJsonPath('kpis.totalRecords', '2')
            ->assertJsonPath('kpis.onTimeCount', '1')
            ->assertJsonPath('kpis.lateCount', '1')
            ->assertJsonPath('kpis.totalHours', '15.5')
            ->assertJsonPath('kpis.overtimeHours', '1.0')
            ->assertJsonPath('counts.late', 1);

        $this->actingAs($sup)->withHeaders($ajax)->get('/reports?preset=today')->assertOk()
            ->assertJsonPath('kpis.totalRecords', '1');

        // La búsqueda filtra la tabla sin cambiar los contadores del periodo
        $res = $this->actingAs($hr)->withHeaders($ajax)->get('/reports?preset=today&search=Beto')->assertOk();
        $this->assertStringContainsString('Beto', $res->json('html'));
        $this->assertStringNotContainsString('Ana', $res->json('html'));
    }

    public function test_logout_by_get_does_not_close_a_live_session(): void
    {
        $acme = $this->makeTenant('acme');
        $hr   = $this->makeUser($acme, 'hr_manager');

        $this->actingAs($hr)->get('/logout')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($hr);

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();

        // Ya sin sesión, el GET del aviso de inactividad sigue llevando al login
        $this->get('/logout?expired=1')->assertRedirect(route('login'))->assertSessionHas('info');
    }

    public function test_login_domain_fallback_comes_from_config_not_code(): void
    {
        config(['app.login_email_domain' => 'midominio.com']);
        $this->assertSame('midominio.com', TenantSetting::forTenant(null)->loginEmailDomain());

        $acme = $this->makeTenant('acme');
        TenantSetting::platform()->forceFill(['login_email_domain' => 'plataforma.pe', 'trade_name' => 'Mi ERP'])->save();
        $this->assertSame('plataforma.pe', TenantSetting::forTenant($acme)->loginEmailDomain());
        $this->assertSame('Mi ERP', brand_name());
    }

    public function test_opening_holidays_never_creates_rows(): void
    {
        $acme = $this->makeTenant('acme');
        $hr   = $this->makeUser($acme, 'hr_manager');

        $this->actingAs($hr)->get('/holidays?year=2027')->assertOk();
        $this->actingAs($hr)->getJson('/holidays?year=2027')->assertOk()->assertJsonCount(0, 'holidays');
        $this->assertSame(0, \App\Modules\HR\Models\Holiday::withoutGlobalScopes()->count());
    }

    public function test_app_config_resolves_company_by_its_own_domain(): void
    {
        $acme = $this->makeTenant('acme', ['custom_domain' => 'asistencia.acme.pe', 'company_name' => 'Acme SAC']);
        TenantSetting::platform()->forceFill(['trade_name' => 'Mi ERP'])->save();

        // En el dominio de la plataforma, sin código: marca de la plataforma
        $this->getJson('/api/v1/app/config')->assertOk()
            ->assertJsonPath('data.tenant', null)
            ->assertJsonPath('data.platform_name', 'Mi ERP');

        $this->getJson('http://asistencia.acme.pe/api/v1/app/config')->assertOk()
            ->assertJsonPath('data.tenant.slug', 'acme')
            ->assertJsonPath('data.platform_name', 'Mi ERP');

        // El código de empresa sigue mandando
        $this->getJson('http://asistencia.acme.pe/api/v1/app/config?tenant_slug=nada')->assertNotFound();
    }
}
