<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * El selector de empresa del topbar se abre siempre debajo del botón: sin
 * Popper (data-bs-display="static"), que lo volteaba hacia arriba.
 */
class TopbarTenantMenuTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_tenant_switcher_opens_below_its_button(): void
    {
        $this->seedRoles();
        $this->makeTenant('acme');

        $this->actingAs($this->makeUser(null, 'super_admin'))
            ->get('/branches')
            ->assertOk()
            ->assertSee('class="topbar-tenant-btn" data-bs-toggle="dropdown" data-bs-display="static"', false);

        $css = file_get_contents(public_path('css/panel.css'));
        $this->assertMatchesRegularExpression('/\.topbar-tenant-menu \{[^}]*top: 100% !important;/', $css);
    }
}
