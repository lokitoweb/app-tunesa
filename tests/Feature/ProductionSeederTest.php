<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
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

    protected function tearDown(): void
    {
        foreach (['SEED_SUPERADMIN_EMAIL', 'SEED_SUPERADMIN_PASSWORD'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    public function test_production_seed_requires_super_admin_credentials(): void
    {
        $this->expectException(RuntimeException::class);
        $this->artisan('db:seed', ['--force' => true])->run();
    }

    public function test_production_seed_creates_only_the_super_admin(): void
    {
        $this->setEnv('SEED_SUPERADMIN_EMAIL', 'owner@cliente.pe');
        $this->setEnv('SEED_SUPERADMIN_PASSWORD', 'Una-clave-larga-123');

        $this->artisan('db:seed', ['--force' => true])->run();

        $root = User::sole();
        $this->assertSame('owner@cliente.pe', $root->email);
        $this->assertTrue($root->isSuperAdmin());
        $this->assertTrue($root->must_change_password);
        $this->assertTrue(Hash::check('Una-clave-larga-123', $root->password));
        $this->assertSame(0, Tenant::count());
    }
}
