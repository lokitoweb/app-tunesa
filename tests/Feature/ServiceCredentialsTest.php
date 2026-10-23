<?php

namespace Tests\Feature;

use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Services\TenantContext;
use App\Modules\Core\Support\TenantMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Credenciales de Migo, Nubefact y SMTP: cifradas, por empresa, solo editables por el SuperAdmin */
class ServiceCredentialsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_super_admin_sets_encrypted_credentials_inherited_by_companies(): void
    {
        $root = $this->makeUser(null, 'super_admin');
        $acme = $this->makeTenant('acme');

        $this->actingAs($root)->put('/settings/integrations', [
            'migo_api_token' => 'migo-secreto-123', 'nubefact_url' => 'https://api.nubefact.com/api/v1/demo',
            'nubefact_token' => 'nube-secreto-456', 'smtp_host' => 'smtp.plataforma.pe', 'smtp_port' => 587,
            'smtp_username' => 'avisos', 'smtp_password' => 'smtp-secreto-789', 'mail_from_address' => 'avisos@plataforma.pe',
        ])->assertSessionHasNoErrors();

        $raw = DB::table('tenant_settings')->whereNull('tenant_id')->first();
        foreach (['migo_api_token' => 'migo-secreto-123', 'nubefact_token' => 'nube-secreto-456', 'smtp_password' => 'smtp-secreto-789'] as $col => $plain) {
            $this->assertStringNotContainsString($plain, $raw->{$col});
        }

        // Acme hereda; luego define su propio token de Migo
        $this->assertSame('migo-secreto-123', TenantSetting::forTenant($acme)->migo()['token']);
        $this->assertSame('smtp.plataforma.pe', TenantSetting::forTenant($acme)->smtp()['host']);
        $this->actingAs($root)->withSession(['active_tenant_id' => $acme->id]);
        TenantSetting::editableRowFor($acme)->fill(['migo_api_token' => 'migo-acme'])->save();
        $this->assertSame('migo-acme', TenantSetting::forTenant($acme)->migo()['token']);

        // La página nunca muestra los secretos completos
        $page = $this->actingAs($root)->get('/settings/integrations')->assertOk();
        $this->assertStringNotContainsString('nube-secreto-456', $page->getContent());
        $this->assertStringNotContainsString('smtp-secreto-789', $page->getContent());
    }

    public function test_company_admin_cannot_change_service_credentials(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');

        foreach (['/settings/integrations', '/settings/branding'] as $path) {
            $this->actingAs($admin)->put($path, [
                'primary_color' => '#1E40AF', 'secondary_color' => '#F59E0B',
                'migo_api_token' => 'robado', 'smtp_host' => 'smtp.atacante.pe', 'nubefact_token' => 'robado',
            ]);
            app(TenantContext::class)->clear();
        }

        $row = TenantSetting::rowFor($acme);
        $this->assertNull($row?->migo_api_token);
        $this->assertNull($row?->smtp_host);
        $this->assertNull($row?->nubefact_token);
        $this->assertNull($this->actingAs($admin)->get('/settings/integrations')->assertOk()->prop('services'));
    }

    public function test_falls_back_to_env_config_and_mailer_uses_company_smtp(): void
    {
        config(['services.migo.token' => 'env-token']);
        $acme = $this->makeTenant('acme');
        $this->assertSame('env-token', TenantSetting::forTenant($acme)->migo()['token']);

        // Sin SMTP: el correo usa el mailer por defecto
        $emp = $this->makeUser($acme);
        $this->assertNull(TenantMailer::apply(new MailMessage, $emp)->mailer);

        TenantSetting::editableRowFor($acme)->fill(['smtp_host' => 'smtp.acme.pe', 'smtp_port' => 465, 'smtp_password' => 'x', 'mail_from_address' => 'rrhh@acme.pe'])->save();
        $mail = TenantMailer::apply(new MailMessage, $emp->fresh());
        $this->assertSame("tenant_smtp_{$acme->id}", $mail->mailer);
        $this->assertSame('smtp.acme.pe', config("mail.mailers.tenant_smtp_{$acme->id}.host"));
        $this->assertSame(['rrhh@acme.pe', $mail->from[1]], $mail->from);
    }

    public function test_ruc_lookup_uses_the_company_token(): void
    {
        $acme  = $this->makeTenant('acme');
        $admin = $this->makeUser($acme, 'company_admin');
        TenantSetting::editableRowFor($acme)->fill(['migo_api_token' => 'migo-acme'])->save();
        Http::fake(['*' => Http::response(['nombre_o_razon_social' => 'PROVEEDOR SAC', 'direccion' => 'Av. Lima 1'])]);

        $this->actingAs($admin)->getJson('/suppliers/ruc/20123456789')->assertOk()->assertJsonPath('data.name', 'PROVEEDOR SAC');
        Http::assertSent(fn (Request $r) => $r['token'] === 'migo-acme');
    }
}
