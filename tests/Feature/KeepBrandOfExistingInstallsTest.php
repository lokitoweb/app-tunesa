<?php

namespace Tests\Feature;

use App\Modules\Core\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

class KeepBrandOfExistingInstallsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function migration(): object
    {
        return require base_path('app/Modules/Core/Database/Migrations/2026_11_06_000001_keep_brand_of_existing_installs.php');
    }

    public function test_a_new_install_stays_neutral(): void
    {
        Storage::fake('public');
        $this->migration()->up();

        $this->assertNull(DB::table('tenant_settings')->whereNull('tenant_id')->value('trade_name'));
        $this->assertSame(config('app.name'), TenantSetting::platform()->tradeName());
        $this->assertTrue(TenantSetting::platform()->usesDefaultLogo());
    }

    public function test_an_installation_in_use_keeps_its_look_and_custom_values(): void
    {
        Storage::fake('public');
        $this->seedRoles();
        $this->makeUser(null, 'super_admin');
        DB::table('tenant_settings')->updateOrInsert(['tenant_id' => null], ['login_email_domain' => 'propio.com']);

        $this->migration()->up();

        $row = DB::table('tenant_settings')->whereNull('tenant_id')->first();
        $this->assertSame('Grupo Negreiros', $row->trade_name);
        $this->assertSame('propio.com', $row->login_email_domain);   // lo personalizado no se toca
        Storage::disk('public')->assertExists([$row->logo_path, $row->logo_dark_path, $row->favicon_path]);

        $this->migration()->up();   // otra corrida no cambia nada
        $this->assertSame($row->logo_path, DB::table('tenant_settings')->whereNull('tenant_id')->value('logo_path'));
    }
}
