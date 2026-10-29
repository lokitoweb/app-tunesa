<?php

namespace Tests\Feature;

use App\Modules\Finance\Models\QuoteRequest;
use App\Modules\Finance\Models\Requisition;
use App\Modules\Finance\Models\Supplier;
use App\Modules\Finance\Notifications\SupplierDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Solicitud de cotización a proveedores (sisgere CREAR_SOLCOTIZACION / CRsolcoti) */
class QuoteRequestTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_one_sc_per_supplier_emailed_with_pdf(): void
    {
        Notification::fake();
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $a = Supplier::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'name' => 'Con correo', 'ruc' => '20444444444', 'email' => 'ventas@prov.test', 'category' => 'FILTROS DE MANTENIMIENTO']);
        $b = Supplier::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'name' => 'Sin correo', 'ruc' => '20555555555']);
        $requester = $this->makeUser($acme, 'supervisor');
        $logistics = $this->makeUser($acme, 'logistics');
        $manager = $this->makeUser($acme, 'manager');

        $this->actingAs($requester)->post('/requisitions', ['requested_at' => now()->toDateString(), 'area' => 'Flota', 'requested_by' => 'Ana', 'detail' => 'Filtros',
            'classification' => 'REPUESTOS', 'priority' => 'ALTO', 'items' => [['product' => 'Filtro', 'unit' => 'UNIDAD (BIENES)', 'quantity' => 6]]]);
        $r = Requisition::withoutGlobalScopes()->sole();
        $payload = ['supplier_ids' => [$a->id, $b->id], 'delivery_mode' => 'delivery', 'delivery_place' => 'Taller Trujillo', 'quote_payment_terms' => 'Crédito 30 días'];

        // Sin aprobar no se solicita; quien pide no puede
        $this->actingAs($logistics)->post("/requisitions/{$r->id}/quote-requests", $payload)->assertStatus(422);
        $this->actingAs($manager)->post("/requisitions/{$r->id}/review", ['decision' => 'approved']);
        $this->actingAs($requester)->post("/requisitions/{$r->id}/quote-requests", $payload)->assertForbidden();
        // El rubro llega a la pantalla para filtrar los proveedores (sisgere MOSTRAR_proveedor_rubro)
        $this->actingAs($logistics)->get("/requisitions/{$r->id}")
            ->assertInertia(fn ($page) => $page->where('suppliers.0.category', 'FILTROS DE MANTENIMIENTO'));
        $this->actingAs($logistics)->post("/requisitions/{$r->id}/quote-requests", ['delivery_place' => ''] + $payload)->assertSessionHasErrors('delivery_place');

        $this->post("/requisitions/{$r->id}/quote-requests", $payload)->assertRedirect()->assertSessionHasNoErrors();
        $codes = QuoteRequest::withoutGlobalScopes()->orderBy('id')->pluck('code')->all();
        $this->assertCount(2, $codes);
        $this->assertStringStartsWith('SC-', $codes[0]);
        $this->assertNotSame($codes[0], $codes[1]);
        Notification::assertSentOnDemand(SupplierDocument::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'ventas@prov.test'
            && str_starts_with((string) $n->attachment, '%PDF') && $n->attachmentName === "{$codes[0]}.pdf");
        Notification::assertSentOnDemandTimes(SupplierDocument::class, 1); // el proveedor sin correo no recibe nada
        $this->assertSame(['delivery', 'Taller Trujillo', 'Crédito 30 días'], [$r->fresh()->delivery_mode, $r->fresh()->delivery_place, $r->fresh()->quote_payment_terms]);

        // Reenviar conserva el SC de cada proveedor
        $this->post("/requisitions/{$r->id}/quote-requests", $payload)->assertSessionHasNoErrors();
        $this->assertSame($codes, QuoteRequest::withoutGlobalScopes()->orderBy('id')->pluck('code')->all());

        $qr = QuoteRequest::withoutGlobalScopes()->first();
        $this->get("/requisitions/{$r->id}/quote-requests/{$qr->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get("/requisitions/{$r->id}")->assertInertia(fn ($p) => $p->where('requisition.quote_requests.0.code', $codes[0]));
    }
}
