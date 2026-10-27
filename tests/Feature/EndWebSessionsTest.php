<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Auditoría de seguridad: al cambiar la contraseña o restablecer el acceso, las sesiones del panel
 * en otros navegadores se cierran (una cookie robada deja de servir).
 */
class EndWebSessionsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function addSession(string $id, int $userId): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
    }

    public function test_ends_the_users_other_panel_sessions_only(): void
    {
        config(['session.driver' => 'database']);
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $ana = $this->makeUser($acme, 'employee');
        $luis = $this->makeUser($acme, 'employee');
        $this->addSession('ana-actual', $ana->id);
        $this->addSession('ana-robada', $ana->id);
        $this->addSession('luis', $luis->id);

        $this->assertSame(1, $ana->endWebSessions('ana-actual'));
        $this->assertSame(['ana-actual', 'luis'], DB::table('sessions')->orderBy('id')->pluck('id')->all());

        // Restablecer el acceso cierra todas las suyas, sin tocar las de otros
        $this->assertSame(1, $ana->endWebSessions());
        $this->assertSame(['luis'], DB::table('sessions')->pluck('id')->all());
    }

    public function test_hr_reset_access_ends_the_employees_panel_sessions(): void
    {
        config(['session.driver' => 'database']);
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $hr = $this->makeUser($acme, 'hr_manager');
        $ana = $this->makeUser($acme, 'employee', ['document_number' => '70112233']);
        $this->addSession('ana-1', $ana->id);

        app(\App\Modules\Core\Services\CredentialProvisioner::class)->resetAccess($ana);
        $this->assertFalse(DB::table('sessions')->where('user_id', $ana->id)->exists());
    }

    public function test_editing_an_employee_with_an_email_of_another_company_is_a_clear_error(): void
    {
        // users.email es único en todo el sistema: antes la edición solo revisaba la empresa y daba 500
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $this->makeUser($this->makeTenant('other'), 'employee', ['email' => 'ocupado@other.pe']);
        app(\App\Modules\Core\Services\TenantContext::class)->clear();
        $hr = $this->makeUser($acme, 'hr_manager');
        $branch = $this->makeBranch($acme);
        $ana = $this->makeUser($acme, 'employee', ['first_name' => 'Ana', 'document_number' => '70112233']);

        $this->actingAs($hr)->put("/employees/{$ana->id}", [
            'first_name' => 'Ana', 'last_name' => 'Q', 'email' => 'ocupado@other.pe', 'status' => 'active', 'role' => 'employee',
            'document_type' => 'dni', 'document_number' => '70112233', 'branch_ids' => [$branch->id], 'primary_branch_id' => $branch->id,
        ])->assertSessionHasErrors('email');
        $this->assertNotSame('ocupado@other.pe', $ana->fresh()->email);
    }

    public function test_other_session_drivers_are_left_alone(): void
    {
        config(['session.driver' => 'array']);
        $this->seedRoles();
        $ana = $this->makeUser($this->makeTenant('acme'), 'employee');
        $this->addSession('ana', $ana->id);

        $this->assertSame(0, $ana->endWebSessions());
        $this->assertTrue(DB::table('sessions')->exists());
    }
}
