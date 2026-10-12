<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * POST /api/v1/auth/login: la app entra solo con correo y contraseña; el
 * servidor reconoce la empresa del usuario (sin X-Tenant-Slug).
 */
class LoginByEmailTest extends TestCase
{
    use RefreshDatabase, CreatesTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_login_without_company_code_detects_tenant_role_and_branch(): void
    {
        $acme   = $this->makeTenant('acme');
        $branch = $this->makeBranch($acme, ['name' => 'Terminal Trujillo']);
        $ana    = $this->makeUser($acme, 'supervisor', ['email' => 'ana@acme.test']);
        $ana->branches()->attach($branch->id, ['is_primary' => true, 'assigned_from' => '2020-01-01']);

        $data = $this->postJson('/api/v1/auth/login', ['email' => 'ana@acme.test', 'password' => 'Secret123'])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'supervisor')
            ->assertJsonPath('data.user.company.slug', 'acme')
            ->json('data');

        // Con el slug devuelto, el token sirve en el resto de la API
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => "Bearer {$data['token']}", 'X-Tenant-Slug' => $data['user']['company']['slug']])
            ->getJson('/api/my-branches')->assertOk()->assertSee('Terminal Trujillo');
    }

    public function test_each_user_lands_in_their_own_company(): void
    {
        $this->makeUser($this->makeTenant('acme'), 'employee', ['email' => 'luis@acme.test']);
        $this->makeUser($this->makeTenant('other'), 'hr_manager', ['email' => 'rosa@other.test']);

        $this->postJson('/api/v1/auth/login', ['email' => 'rosa@other.test', 'password' => 'Secret123'])
            ->assertOk()->assertJsonPath('data.user.company.slug', 'other')->assertJsonPath('data.user.role', 'hr_manager');
        $this->postJson('/api/v1/auth/login', ['email' => 'luis@acme.test', 'password' => 'Secret123'])
            ->assertOk()->assertJsonPath('data.user.company.slug', 'acme')->assertJsonPath('data.user.role', 'employee');
    }

    public function test_wrong_credentials_inactive_accounts_and_platform_users_are_refused(): void
    {
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme, 'employee', ['email' => 'ana@acme.test']);
        $this->makeUser(null, 'super_admin', ['email' => 'root@plataforma.test']);

        $this->postJson('/api/v1/auth/login', ['email' => 'ana@acme.test', 'password' => 'incorrecta'])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        $this->assertSame(1, $ana->fresh()->failed_login_attempts);
        $this->postJson('/api/v1/auth/login', ['email' => 'nadie@acme.test', 'password' => 'Secret123'])->assertStatus(422);

        // El Super Admin no pertenece a ninguna empresa: entra por su propio login
        $this->postJson('/api/v1/auth/login', ['email' => 'root@plataforma.test', 'password' => 'Secret123'])->assertStatus(422);

        $ana->update(['status' => 'suspended']);
        $this->postJson('/api/v1/auth/login', ['email' => 'ana@acme.test', 'password' => 'Secret123'])
            ->assertStatus(403)->assertJsonPath('code', 'USER_INACTIVE');

        $ana->update(['status' => 'active']);
        $acme->update(['status' => 'suspended']);
        $this->postJson('/api/v1/auth/login', ['email' => 'ana@acme.test', 'password' => 'Secret123'])
            ->assertStatus(403)->assertJsonPath('code', 'TENANT_INACTIVE');
    }

    public function test_user_changes_profile_photo_and_the_old_file_is_removed(): void
    {
        Storage::fake('public');
        $acme = $this->makeTenant('acme');
        $ana  = $this->makeUser($acme, 'employee', ['email' => 'ana@acme.test']);

        $first = $this->actingIn($ana, $acme)
            ->post('/api/auth/profile', ['avatar' => UploadedFile::fake()->image('yo.jpg', 400, 400)], ['Accept' => 'application/json'])
            ->assertOk()->json('data.avatar_url');
        $this->assertNotNull($first);
        $old = $ana->fresh()->avatar_path;
        Storage::disk('public')->assertExists($old);

        $this->post('/api/auth/profile', ['avatar' => UploadedFile::fake()->image('otra.png')], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($ana->fresh()->avatar_path);

        $this->post('/api/auth/profile', ['avatar' => UploadedFile::fake()->create('cv.pdf', 10)], ['Accept' => 'application/json'])->assertStatus(422);

        // Quitar la foto borra el archivo y vuelven las iniciales
        $current = $ana->fresh()->avatar_path;
        $this->putJson('/api/auth/profile', ['remove_avatar' => true])->assertOk()->assertJsonPath('data.avatar_url', null);
        $this->assertNull($ana->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($current);
    }

    public function test_app_password_reset_is_handled_by_hr(): void
    {
        $this->makeUser($this->makeTenant('acme'), 'employee', ['email' => 'ana@acme.test']);

        foreach (['/api/v1/auth/forgot-password', '/api/auth/forgot-password', '/api/auth/reset-password'] as $url) {
            $this->withHeaders(['X-Tenant-Slug' => 'acme'])->postJson($url, ['email' => 'ana@acme.test'])->assertStatus(410)->assertJsonPath('code', 'PASSWORD_RESET_BY_ADMIN');
        }
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'ana@acme.test']);
    }
}
