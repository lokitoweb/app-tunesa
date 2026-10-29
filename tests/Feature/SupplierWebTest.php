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
            ->assertInertia(fn ($page) => $page->component('Finance/Pages/Suppliers/Index')->where('can_manage', true)
                // Catálogos de FrmRegProveedor: tipo de cuenta fijo y rubros aunque la empresa no tenga proveedores
                ->where('account_types', ['CTE. SOLES', 'CTE. DOLARES', 'CCI. SOLES', 'CCI. DOLARES'])
                ->where('categories', fn ($c) => collect($c)->contains('COMBUSTIBLE'))
                ->has('banks'));

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

    public function test_registering_the_ruc_of_a_deleted_supplier_reactivates_it_instead_of_failing(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $user = $this->makeUser($acme, 'logistics');
        $this->actingAs($user)->post('/suppliers', ['name' => 'Ferretería Sur SAC', 'ruc' => '20123456789'])->assertSessionHasNoErrors();
        $old = Supplier::withoutGlobalScopes()->sole();
        $this->delete("/suppliers/{$old->id}");

        // Antes: la validación ignoraba al eliminado y la base respondía 500 (índice único tenant_id + ruc)
        $this->post('/suppliers', ['name' => 'Ferretería Sur (nueva razón)', 'ruc' => '20123456789', 'city' => 'Piura'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Ese RUC era de un proveedor eliminado: se reactivó con los datos nuevos.');
        $supplier = Supplier::withoutGlobalScopes()->sole(); // el mismo, sin duplicar
        $this->assertSame([$old->id, 'Ferretería Sur (nueva razón)', 'Piura', null], [$supplier->id, $supplier->name, $supplier->city, $supplier->deleted_at]);

        // Editar otro proveedor para ponerle el RUC de uno eliminado: mensaje claro, no 500
        $other = Supplier::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'name' => 'Otro', 'ruc' => '20999999999']);
        $this->delete("/suppliers/{$supplier->id}");
        $this->put("/suppliers/{$other->id}", ['name' => 'Otro', 'ruc' => '20123456789'])->assertSessionHasErrors('ruc');

        // Otra empresa puede registrar el mismo RUC
        $other2 = $this->makeTenant('other');
        app(\App\Modules\Core\Services\TenantContext::class)->clear();
        $this->actingAs($this->makeUser($other2, 'logistics'))->post('/suppliers', ['name' => 'Sur en otra empresa', 'ruc' => '20123456789'])->assertSessionHasNoErrors();
        $this->assertSame(1, Supplier::withoutGlobalScopes()->where('tenant_id', $other2->id)->count());
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $foreign = Supplier::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'name' => 'Ajeno', 'ruc' => '20999999999']);

        $this->actingAs($this->makeUser($acme, 'employee'))->get('/suppliers')->assertRedirect(route('portal.home')); // sin acceso al panel

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

    public function test_dni_lookup_fills_the_name_of_a_natural_person(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $user = $this->makeUser($acme, 'logistics');
        config(['services.migo.token' => 'tok-test', 'services.migo.dni_url' => 'https://migo.test/dni']);

        Http::fake(['migo.test/dni' => Http::sequence()
            ->push(['success' => true, 'dni' => '44556677', 'nombre' => 'QUISPE MAMANI ROSA'])
            ->push(['errors' => 'DNI no encontrado'])
            ->pushStatus(500)]);

        $this->actingAs($user)->getJson('/suppliers/dni/44556677')
            ->assertOk()->assertJsonPath('data.name', 'QUISPE MAMANI ROSA');
        Http::assertSent(fn ($req) => $req->url() === 'https://migo.test/dni' && $req['dni'] === '44556677' && $req['token'] === 'tok-test');

        // Como el .NET: si la respuesta trae "errors" no se usa
        $this->actingAs($user)->getJson('/suppliers/dni/11111111')->assertNotFound()->assertJsonPath('success', false);
        // Servicio caído: se avisa que no respondió (no que el DNI no existe) y no se inventa un nombre
        $this->actingAs($user)->getJson('/suppliers/dni/22222222')->assertStatus(502)->assertJsonPath('message', 'Migo no respondió. Completa los datos a mano.');

        $this->actingAs($user)->getJson('/suppliers/dni/123')->assertNotFound();   // solo 8 dígitos

        config(['services.migo.token' => null]);
        $this->actingAs($user)->getJson('/suppliers/dni/44556677')->assertStatus(503);
    }
}
