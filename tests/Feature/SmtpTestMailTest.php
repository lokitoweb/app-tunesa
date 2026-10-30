<?php

namespace Tests\Feature;

use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Integraciones › Correo (SMTP): "Enviar correo de prueba" usa el SMTP en efecto (el mismo de las contraseñas temporales) */
class SmtpTestMailTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_without_smtp_and_a_log_server_it_explains_that_no_mail_goes_out(): void
    {
        config(['mail.default' => 'log']);
        $root = $this->makeUser(null, 'super_admin');

        $this->actingAs($root)->postJson('/settings/branding/test-mail')->assertOk()
            ->assertJson(['ok' => false])
            ->assertJsonPath('error', fn ($e) => str_contains($e, 'No hay un servidor SMTP configurado'));

        $this->actingAs($root)->get('/settings/integrations')->assertOk()
            ->assertInertia(fn ($p) => $p->where('services.effective.server_mailer_sends', false)->where('services.effective.smtp', null));
    }

    public function test_a_working_mailer_sends_and_is_audited(): void
    {
        // Un mailer que "sale" (transporte en memoria con otro nombre que log/array)
        config(['mail.default' => 'pruebas', 'mail.mailers.pruebas' => ['transport' => 'array']]);
        $root = $this->makeUser(null, 'super_admin');

        $this->actingAs($root)->postJson('/settings/branding/test-mail', ['email' => 'destino@acme.pe'])->assertOk()
            ->assertJson(['ok' => true])->assertJsonPath('message', 'Enviado a destino@acme.pe. Revisa la bandeja de entrada (y spam).');
        $this->assertSame('destino@acme.pe', AuditLog::where('action', 'mail.tested')->sole()->meta['context']['to'] ?? null);
    }

    public function test_smtp_saved_in_the_panel_is_the_one_used_and_errors_are_readable(): void
    {
        config(['mail.default' => 'log']);
        TenantSetting::editableRowFor(null)->fill(['smtp_host' => '127.0.0.1', 'smtp_port' => 1, 'smtp_encryption' => 'tls', 'smtp_username' => 'x'])->save();
        $root = $this->makeUser(null, 'super_admin');

        $this->actingAs($root)->postJson('/settings/branding/test-mail')->assertOk()
            ->assertJson(['ok' => false])
            ->assertJsonPath('error', fn ($e) => str_starts_with($e, 'No se pudo enviar:') && str_contains($e, 'Revisa servidor, puerto'));

        $this->actingAs($root)->get('/settings/integrations')->assertOk()
            ->assertInertia(fn ($p) => $p->where('services.effective.smtp', '127.0.0.1:1'));
    }

    public function test_only_the_super_admin_can_test_the_smtp(): void
    {
        $admin = $this->makeUser($this->makeTenant('acme'), 'company_admin');

        $this->actingAs($admin)->postJson('/settings/branding/test-mail')->assertForbidden();
    }
}
