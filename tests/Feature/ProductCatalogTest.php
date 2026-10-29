<?php

namespace Tests\Feature;

use App\Modules\Finance\Models\Product;
use App\Modules\Finance\Models\RequisitionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Catálogo de productos (sisgere Modificar_producto / eliminar_producto) */
class ProductCatalogTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_correct_and_delete_products_without_breaking_history(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $requester = $this->makeUser($acme, 'supervisor');
        $logistics = $this->makeUser($acme, 'logistics');

        $unused = Product::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'name' => 'Sin uso', 'unit' => 'CAJA']);
        $legacy = Product::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'name' => 'Migrado', 'unit' => 'UND', 'classification' => 'GASTOS FIJOS']);
        $foreign = Product::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'name' => 'Ajeno', 'unit' => 'CAJA']);
        $this->actingAs($requester)->post('/requisitions', ['requested_at' => now()->toDateString(), 'area' => 'Flota', 'requested_by' => 'Ana', 'detail' => 'Llantas',
            'classification' => 'REPUESTOS', 'priority' => 'ALTO', 'items' => [['product' => 'Llanta 295 mal escrita', 'unit' => 'UNIDAD (BIENES)', 'quantity' => 2]]]);
        $used = Product::withoutGlobalScopes()->where('name', 'Llanta 295 mal escrita')->sole();

        $this->actingAs($requester)->get('/products')->assertForbidden();
        $this->actingAs($logistics)->get('/products')->assertOk()->assertInertia(fn ($p) => $p->component('Finance/Pages/Products/Index')->where('products.total', 3));

        // Corregir el nombre: el requerimiento ya muestra el nombre bueno
        $this->put("/products/{$used->id}", ['name' => 'Llanta 295/80 R22.5', 'unit' => 'UNIDAD (BIENES)', 'classification' => 'REPUESTOS'])->assertSessionHasNoErrors();
        $this->assertSame('Llanta 295/80 R22.5', RequisitionItem::withoutGlobalScopes()->sole()->product->name);
        $this->put("/products/{$unused->id}", ['name' => 'llanta 295/80 r22.5', 'unit' => 'CAJA'])->assertSessionHasErrors('name');
        // El migrado conserva su unidad y clasificación de entonces
        $this->put("/products/{$legacy->id}", ['name' => 'Migrado OK', 'unit' => 'UND', 'classification' => 'GASTOS FIJOS'])->assertSessionHasNoErrors();
        $this->put("/products/{$legacy->id}", ['name' => 'Migrado OK', 'unit' => 'INVENTADA'])->assertSessionHasErrors('unit');

        // Usado no se elimina; sin uso sí; de otra empresa ni se ve
        $this->delete("/products/{$used->id}")->assertStatus(422);
        $this->delete("/products/{$unused->id}")->assertSessionHasNoErrors();
        $this->assertNull(Product::withoutGlobalScopes()->find($unused->id));
        $this->delete("/products/{$foreign->id}")->assertNotFound();
    }
}
