<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Organización en la vista global del Super Admin (todas las empresas) también carga */
class OrganizationGlobalViewTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_the_super_admin_global_view_loads(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        Position::create(['tenant_id' => $acme->id, 'name' => 'Chofer', 'trip_control' => true, 'is_active' => true]);

        $this->actingAs($this->makeUser(null, 'super_admin'))->get('/organization')->assertOk();
    }
}
