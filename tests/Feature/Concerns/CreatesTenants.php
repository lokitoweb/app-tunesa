<?php

namespace Tests\Feature\Concerns;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * Helpers para armar empresas, usuarios y sucursales en pruebas de feature.
 */
trait CreatesTenants
{
    protected function seedRoles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function makeTenant(string $slug, array $attrs = []): Tenant
    {
        return Tenant::create(array_merge([
            'slug'         => $slug,
            'company_name' => Str::title(str_replace('-', ' ', $slug)),
            'email'        => "{$slug}@example.test",
            'status'       => 'active',
            'timezone'     => 'America/Lima',
        ], $attrs));
    }

    protected function makeUser(?Tenant $tenant, string $role = 'employee', array $attrs = []): User
    {
        return User::create(array_merge([
            'tenant_id'  => $tenant?->id,
            'first_name' => ucfirst($role),
            'last_name'  => Str::random(6),
            'email'      => Str::random(10) . '@example.test',
            'password'   => 'Secret123',
            'role'       => $role,
            'status'     => 'active',
        ], $attrs));
    }

    protected function makeBranch(Tenant $tenant, array $attrs = []): Branch
    {
        return Branch::create(array_merge([
            'tenant_id'             => $tenant->id,
            'name'                  => 'Sede ' . Str::random(4),
            'latitude'              => -12.09418,
            'longitude'             => -77.03560,
            'allowed_radius_meters' => 100,
            'is_active'             => true,
        ], $attrs));
    }

    /** Cabeceras para una petición autenticada en la empresa dada */
    protected function actingIn(User $user, Tenant $tenant): static
    {
        $token = $user->createToken('test')->plainTextToken;

        // El guard de Sanctum memoriza el usuario entre peticiones del mismo test
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Slug' => $tenant->slug,
            'Accept'        => 'application/json',
        ]);
    }
}
