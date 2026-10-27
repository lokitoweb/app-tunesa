<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Fase 3 — Login móvil endurecido, alta solo por RRHH y jerarquía de rangos.
 */
class AuthHardeningTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function login(string $slug, string $email, string $password)
    {
        return $this->withHeaders(['X-Tenant-Slug' => $slug, 'Accept' => 'application/json'])
            ->postJson('/api/auth/login', ['email' => $email, 'password' => $password, 'device_name' => 'pixel']);
    }

    public function test_public_self_registration_no_longer_exists(): void
    {
        $this->makeTenant('acme', ['settings' => ['allow_self_registration' => true]]);

        $this->withHeaders(['X-Tenant-Slug' => 'acme'])->postJson('/api/auth/register', [
            'first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@y.z', 'password' => 'Secret123', 'password_confirmation' => 'Secret123',
        ])->assertNotFound();

        $this->assertSame(0, User::count());
    }

    public function test_passwords_are_hashed_with_argon2id(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme, 'employee', ['password' => 'Secret123']);

        $this->assertStringStartsWith('$argon2id$', $ana->fresh()->password);
    }

    public function test_legacy_bcrypt_hash_still_works_and_is_upgraded_on_login(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme, 'employee', ['email' => 'ana@acme.pe']);
        // Hash heredado (antes de la Fase 3) escrito tal cual en la base de datos
        DB::table('users')->where('id', $ana->id)->update(['password' => Hash::driver('bcrypt')->make('Secret123')]);

        $this->login('acme', 'ana@acme.pe', 'Secret123')->assertOk();
        $this->assertStringStartsWith('$argon2id$', $ana->fresh()->password);
    }

    public function test_account_is_locked_after_repeated_failures(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme, 'employee', ['email' => 'ana@acme.pe', 'password' => 'Secret123']);

        for ($i = 0; $i < 5; $i++) {
            $this->login('acme', 'ana@acme.pe', 'mala-clave')->assertStatus(422);
        }

        $this->assertTrue($ana->fresh()->isLocked());

        // Ni con la contraseña correcta mientras dure el bloqueo
        $this->login('acme', 'ana@acme.pe', 'Secret123')->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.account_locked', 'subject_id' => $ana->id]);

        $this->travel(16)->minutes();
        $this->login('acme', 'ana@acme.pe', 'Secret123')->assertOk();
        $this->assertSame(0, $ana->fresh()->failed_login_attempts);
        $this->assertNotNull($ana->fresh()->last_login_at);
    }

    public function test_locked_account_is_refused_from_another_ip(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme, 'employee', ['email' => 'ana@acme.pe', 'password' => 'Secret123']);
        $ana->forceFill(['locked_until' => now()->addMinutes(10)])->saveQuietly();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->login('acme', 'ana@acme.pe', 'Secret123')
            ->assertStatus(423)->assertJsonPath('code', 'ACCOUNT_LOCKED');
    }

    public function test_tokens_expire_are_revocable_and_limited_per_user(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme, 'employee', ['email' => 'ana@acme.pe', 'password' => 'Secret123']);

        $res = $this->login('acme', 'ana@acme.pe', 'Secret123')->assertOk();
        // El prefijo es configurable por instalación (SANCTUM_TOKEN_PREFIX); lo que importa es que exista
        $prefix = (string) config('sanctum.token_prefix');
        $this->assertNotSame('', $prefix, 'Sin prefijo, el secret scanning no detecta tokens filtrados');
        $this->assertMatchesRegularExpression('/^\d+\|' . preg_quote($prefix, '/') . '/', $res->json('data.token'), 'Prefijo detectable por secret scanning');
        $this->assertNotNull($res->json('data.expires_at'));

        foreach (['tablet', 'web', 'otro'] as $device) {
            $this->withHeaders(['X-Tenant-Slug' => 'acme'])->postJson('/api/auth/login', ['email' => 'ana@acme.pe', 'password' => 'Secret123', 'device_name' => $device])->assertOk();
        }
        $this->assertSame(3, $ana->tokens()->count(), 'Máximo 3 dispositivos con sesión');

        $token = $ana->tokens()->latest('id')->first();
        $this->travel(31)->days();
        $this->app['auth']->forgetGuards();
        $plain = $this->login('acme', 'ana@acme.pe', 'Secret123')->json('data.token');

        $this->withHeaders(['Authorization' => "Bearer {$plain}", 'X-Tenant-Slug' => 'acme'])
            ->getJson('/api/auth/sessions')->assertOk();

        $this->withHeaders(['Authorization' => "Bearer {$plain}", 'X-Tenant-Slug' => 'acme'])
            ->postJson('/api/auth/logout-all')->assertOk();
        $this->assertSame(0, $ana->tokens()->count());
        $this->assertNotNull($token);
    }

    public function test_deactivating_a_user_revokes_all_tokens(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme);
        $ana->createToken('a');
        $ana->createToken('b');

        $ana->update(['status' => 'inactive']);

        $this->assertSame(0, $ana->tokens()->count());
    }

    public function test_temporary_password_must_be_changed_before_using_the_api(): void
    {
        $acme = $this->makeTenant('acme');
        $hr   = $this->makeUser($acme, 'hr_manager');

        $created = $this->actingIn($hr, $acme)->postJson('/api/admin/employees', [
            'first_name' => 'Nuevo', 'last_name' => 'Ingreso', 'email' => 'nuevo@acme.pe',
            'password' => 'Temporal123', 'role' => 'employee',
        ])->assertCreated()->assertJsonPath('data.must_change_password', true);
        $temp = $created->json('credentials.temporary_password');
        $this->assertNotSame('Temporal123', $temp, 'la contraseña temporal la genera el sistema');

        $res = $this->login('acme', 'nuevo@acme.pe', $temp)->assertOk()->assertJsonPath('data.must_change_password', true);
        $this->app['auth']->forgetGuards();
        $headers = ['Authorization' => 'Bearer ' . $res->json('data.token'), 'X-Tenant-Slug' => 'acme'];

        $this->withHeaders($headers)->getJson('/api/employee/profile')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->withHeaders($headers)->getJson('/api/auth/me')->assertOk();

        $this->withHeaders($headers)->putJson('/api/auth/change-password', [
            'current_password' => $temp, 'new_password' => 'debil', 'new_password_confirmation' => 'debil',
        ])->assertStatus(422);

        $this->withHeaders($headers)->putJson('/api/auth/change-password', [
            'current_password' => $temp, 'new_password' => 'Segura2026x', 'new_password_confirmation' => 'Segura2026x',
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers)->getJson('/api/employee/profile')->assertOk();
    }

    public function test_hr_cannot_create_or_promote_to_a_higher_rank(): void
    {
        $acme  = $this->makeTenant('acme');
        $hr    = $this->makeUser($acme, 'hr_manager');
        $admin = $this->makeUser($acme, 'company_admin');

        $this->actingIn($hr, $acme)->postJson('/api/admin/employees', [
            'first_name' => 'X', 'last_name' => 'Y', 'email' => 'boss@acme.pe', 'password' => 'Temporal123', 'role' => 'company_admin',
        ])->assertForbidden();

        $this->actingIn($hr, $acme)->postJson('/api/admin/employees', [
            'first_name' => 'X', 'last_name' => 'Y', 'email' => 'hr2@acme.pe', 'password' => 'Temporal123', 'role' => 'hr_manager',
        ])->assertForbidden();

        $this->actingIn($hr, $acme)->putJson("/api/admin/employees/{$admin->id}", ['status' => 'inactive'])->assertForbidden();
        $this->assertSame('active', $admin->fresh()->status);

        $this->actingIn($admin, $acme)->postJson('/api/admin/employees', [
            'first_name' => 'X', 'last_name' => 'Y', 'email' => 'hr2@acme.pe', 'password' => 'Temporal123', 'role' => 'hr_manager',
        ])->assertCreated();
    }
}
