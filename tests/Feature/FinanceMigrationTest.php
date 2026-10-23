<?php

namespace Tests\Feature;

use App\Modules\Finance\Models\LegacyIdMap;
use App\Modules\Finance\Models\Supplier;
use App\Modules\Finance\Support\LegacyLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Compras y tesorería: permisos por rol y la importación sin duplicados */
class FinanceMigrationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_finance_permissions_follow_the_role_matrix(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');

        $this->assertTrue($this->makeUser(null, 'super_admin')->hasPermission('treasury.pay'));
        $this->assertTrue($this->makeUser($acme, 'super_admin')->hasPermission('attendance.view_all'));
        $this->assertTrue($this->makeUser($acme, 'company_admin')->hasPermission('treasury.pay'));
        $this->assertTrue($this->makeUser($acme, 'accountant')->hasPermission('treasury.pay'));
        $this->assertTrue($this->makeUser($acme, 'logistics')->hasPermission('purchasing.manage'));
        $this->assertFalse($this->makeUser($acme, 'logistics')->hasPermission('treasury.pay'));
        $this->assertFalse($this->makeUser($acme, 'hr_manager')->hasPermission('treasury.pay'));
        $this->assertTrue($this->makeUser($acme, 'hr_manager')->hasPermission('attendance.view_all'));
        $this->assertFalse($this->makeUser($acme, 'employee')->hasPermission('suppliers.view'));
    }

    public function test_legacy_import_links_once_and_matches_people_by_dni_then_email(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $ana = $this->makeUser($acme, 'employee', ['document_number' => '12345678', 'email' => 'ana@acme.test']);
        $luis = $this->makeUser($acme, 'employee', ['document_number' => '87654321', 'email' => 'luis@acme.test']);

        $make = fn () => Supplier::create(['tenant_id' => $acme->id, 'name' => 'Ferretería Sur', 'ruc' => '20123456789']);
        $first = LegacyLinker::link($acme->id, 'tproveedor', 15, $make);
        $again = LegacyLinker::link($acme->id, 'tproveedor', 15, $make);

        $this->assertTrue($first->is($again));
        $this->assertSame(1, Supplier::withoutGlobalScopes()->count());
        $this->assertSame(1, LegacyIdMap::count());

        $this->assertTrue($ana->is(LegacyLinker::findUser($acme->id, '12.345.678', null)));
        $this->assertTrue($ana->is(LegacyLinker::findUser($acme->id, null, ' ANA@acme.test ')));
        $this->assertFalse(LegacyLinker::findUser($acme->id, '12345678', 'luis@acme.test'));
        $this->assertNull(LegacyLinker::findUser($acme->id, '00000000', 'nadie@acme.test'));
        $this->assertNull(LegacyLinker::findUser($this->makeTenant('otra')->id, '12345678', null));
        $this->assertNotNull($luis);
    }
}
