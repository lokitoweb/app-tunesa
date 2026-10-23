<?php

namespace Tests\Feature;

use App\Modules\Finance\Models\Supplier;
use App\Modules\Finance\Models\SupplierBankAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Pantalla de proveedores: permisos, CRUD con cuentas, aislamiento y RUC en Migo */
class SupplierWebTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_logistics_manages_suppliers_with_bank_accounts(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $user = $this->makeUser($acme, 'logistics');

        $this->actingAs($user)->get('/suppliers')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Finance/Pages/Suppliers/Index')->where('can_manage', true));

        $this->actingAs($user)->post('/suppliers', [
            'name' => 'Ferretería <b>Sur</b>', 'ruc' => '20123456789', 'is_active' => true,
            'bank_accounts' => [['bank' => 'BCP', 'account_number' => '191-1234567-0-11', 'account_type' => 'Corriente']],
        ])->assertSessionHasNoErrors();

        $supplier = Supplier::withoutGlobalScopes()->sole();
        $this->assertSame($acme->id, $supplier->tenant_id);
        $this->assertSame(1, $supplier->bankAccounts()->count());

        // RUC repetido en la misma empresa: error de validación, no duplicado
        $this->actingAs($user)->post('/suppliers', ['name' => 'Otro', 'ruc' => '20123456789'])->assertSessionHasErrors('ruc');

        // Editar reemplaza las cuentas
        $this->actingAs($user)->put("/suppliers/{$supplier->id}", [
            'name' => 'Ferretería Sur SAC', 'ruc' => '20123456789', 'is_active' => false,
            'bank_accounts' => [['bank' => 'BBVA', 'account_number' => '0011-0100'], ['bank' => 'IBK', 'account_number' => '200-300']],
        ])->assertSessionHasNoErrors();
        $this->assertFalse($supplier->fresh()->is_active);
        $this->assertSame(['BBVA', 'IBK'], SupplierBankAccount::withoutGlobalScopes()->orderBy('bank')->pluck('bank')->all());

        $this->actingAs($user)->delete("/suppliers/{$supplier->id}")->assertSessionHasNoErrors();
        $this->assertSoftDeleted($supplier);
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $foreign = Supplier::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'name' => 'Ajeno', 'ruc' => '20999999999']);

        $this->actingAs($this->makeUser($acme, 'employee'))->get('/suppliers')->assertRedirect('/login'); // sin acceso al panel

        $supervisor = $this->makeUser($acme, 'supervisor');
        $this->actingAs($supervisor)->get('/suppliers')->assertOk()
            ->assertInertia(fn ($page) => $page->where('can_manage', false)->where('suppliers.total', 0));
        $this->actingAs($supervisor)->post('/suppliers', ['name' => 'X'])->assertForbidden();

        $admin = $this->makeUser($acme, 'company_admin');
        $this->actingAs($admin)->put("/suppliers/{$foreign->id}", ['name' => 'Hackeado'])->assertNotFound();
        $this->actingAs($admin)->delete("/suppliers/{$foreign->id}")->assertNotFound();
        $this->assertSame('Ajeno', $foreign->fresh()->name);
    }

    public function test_ruc_lookup_uses_migo_with_the_legacy_token(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        config(['services.migo.token' => 'tok-test', 'services.migo.ruc_url' => 'https://migo.test/ruc']);
        Http::fake(['migo.test/*' => Http::response(['nombre_o_razon_social' => 'FERRETERIA SUR SAC', 'direccion' => 'AV. LIMA 123', 'distrito' => 'CUSCO'])]);

        $this->actingAs($this->makeUser($acme, 'logistics'))->getJson('/suppliers/ruc/20123456789')
            ->assertOk()->assertJsonPath('data.name', 'FERRETERIA SUR SAC')->assertJsonPath('data.city', 'CUSCO');

        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Token token=tok-test') && $req['ruc'] === '20123456789');
    }
}
