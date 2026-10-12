<?php

namespace Tests\Feature;

use App\Modules\Core\Controllers\Web\LegalController;
use App\Modules\Core\Models\AccountDeletionRequest;
use App\Modules\Core\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Requisitos de Google Play: política de privacidad pública y forma de pedir
 * la eliminación de la cuenta desde la app y desde una web sin sesión.
 */
class AccountDeletionTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_privacy_and_deletion_pages_are_public(): void
    {
        $this->get('/privacidad')
            ->assertOk()
            ->assertSee('Política de privacidad')
            ->assertSee('no</strong> usa tu ubicación en segundo plano', false)
            ->assertSee(route('legal.account-deletion'));

        $this->get('/eliminar-cuenta')->assertOk()->assertSee('Eliminar mi cuenta');
    }

    public function test_app_config_links_the_legal_pages(): void
    {
        $this->getJson('/api/v1/app/config')
            ->assertOk()
            ->assertJsonPath('data.legal.privacy_policy_url', route('legal.privacy'))
            ->assertJsonPath('data.legal.account_deletion_url', route('legal.account-deletion'));
    }

    public function test_employee_requests_deletion_from_the_app_and_hr_is_notified_once(): void
    {
        $acme  = $this->makeTenant('acme');
        $ana   = $this->makeUser($acme, 'employee', ['email' => 'ana@acme.pe']);
        $rrhh  = $this->makeUser($acme, 'hr_manager', ['email' => 'rrhh@acme.pe']);
        $admin = $this->makeUser($acme, 'company_admin', ['email' => 'admin@acme.pe']);
        app(TenantContext::class)->clear();

        $this->actingIn($ana, $acme)
            ->postJson('/api/auth/account-deletion', ['reason' => 'Ya no trabajo aquí'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending');

        // Repetir no duplica el pedido ni el aviso
        $this->actingIn($ana->fresh(), $acme)->postJson('/api/auth/account-deletion')->assertStatus(202);

        $this->assertSame(1, AccountDeletionRequest::count());
        $saved = AccountDeletionRequest::first();
        $this->assertSame([$acme->id, $ana->id, 'app', 'Ya no trabajo aquí'], [$saved->tenant_id, $saved->user_id, $saved->source, $saved->reason]);

        $this->assertSame(['account.deletion_requested'], $rrhh->notifications()->get()->pluck('data.type')->all());
        $this->assertSame(0, $admin->notifications()->count());
        $this->assertSame(0, $ana->notifications()->where('type', 'account.deletion_requested')->count());
    }

    public function test_web_request_does_not_reveal_whether_the_account_exists(): void
    {
        $acme  = $this->makeTenant('acme');
        $ana   = $this->makeUser($acme, 'employee', ['email' => 'ana@acme.pe']);
        $admin = $this->makeUser($acme, 'company_admin', ['email' => 'admin@acme.pe']);
        app(TenantContext::class)->clear();

        $this->post('/eliminar-cuenta', ['email' => 'ANA@acme.pe'])
            ->assertRedirect(route('legal.account-deletion'))
            ->assertSessionHas('status', LegalController::DELETION_RECEIVED);

        $this->post('/eliminar-cuenta', ['email' => 'nadie@acme.pe'])
            ->assertSessionHas('status', LegalController::DELETION_RECEIVED);

        $this->assertSame($ana->id, AccountDeletionRequest::where('email', 'ana@acme.pe')->value('user_id'));
        $this->assertNull(AccountDeletionRequest::where('email', 'nadie@acme.pe')->value('user_id'));
        // Sin RRHH, el aviso llega al administrador
        $this->assertSame(['account.deletion_requested'], $admin->notifications()->get()->pluck('data.type')->all());

        $this->post('/eliminar-cuenta', ['email' => 'no-es-correo'])->assertSessionHasErrors('email');
    }
}
