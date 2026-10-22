<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class ProductionSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'production';
    }

    // Se leen de config('app.seed_superadmin'), no con env(): así funciona con config:cache
    private function setSeedCredentials(string $email, string $password): void
    {
        config(['app.seed_superadmin' => ['email' => $email, 'password' => $password]]);
    }

    public function test_production_seed_requires_super_admin_credentials(): void
    {
        $this->expectException(RuntimeException::class);
        $this->artisan('db:seed', ['--force' => true])->run();
    }

    public function test_production_seed_creates_only_the_super_admin(): void
    {
        $this->setSeedCredentials('owner@cliente.pe', 'Una-clave-larga-123');

        $this->artisan('db:seed', ['--force' => true])->run();

        $root = User::sole();
        $this->assertSame('owner@cliente.pe', $root->email);
        $this->assertTrue($root->isSuperAdmin());
        $this->assertTrue($root->must_change_password);
        $this->assertTrue(Hash::check('Una-clave-larga-123', $root->password));
        $this->assertSame(0, Tenant::count());
    }

    public function test_production_seed_rejects_a_short_password(): void
    {
        $this->setSeedCredentials('owner@cliente.pe', 'corta');

        $this->expectException(RuntimeException::class);
        $this->artisan('db:seed', ['--force' => true])->run();
    }
}
