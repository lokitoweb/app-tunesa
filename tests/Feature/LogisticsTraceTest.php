<?php

namespace Tests\Feature;

use App\Modules\Core\Services\TenantContext;
use App\Modules\Finance\Models\CompanyBankAccount;
use App\Modules\Finance\Models\FuelLoad;
use App\Modules\Finance\Models\PaymentInstallment;
use App\Modules\Finance\Models\Product;
use App\Modules\Finance\Models\Requisition;
use App\Modules\Finance\Models\Signatory;
use App\Modules\Finance\Models\StockMovement;
use App\Modules\Finance\Models\Supplier;
use App\Modules\Finance\Models\Vehicle;
use App\Modules\Finance\Notifications\RequisitionReviewed;
use App\Modules\Finance\Notifications\SupplierDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTenants;
use Tests\TestCase;

/** Traza RX -> Cotización PDF -> OC -> Almacén -> Factura -> OP -> Firma APP -> Voucher, y maestros */
class LogisticsTraceTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_full_trace_from_requisition_to_payment_with_cross_validations(): void
    {
        Storage::fake('private');
        Notification::fake();
        $this->seedRoles();
        $acme = $this->makeTenant('acme', ['company_name' => 'Turismo Negreiros']);
        $branch = $this->makeBranch($acme, ['code' => 'SED-01', 'document_series' => '001']);
        $requester = $this->makeUser($acme, 'supervisor');
        $requester->branches()->attach($branch->id, ['is_primary' => true]);
        $manager = $this->makeUser($acme, 'manager');
        $logistics = $this->makeUser($acme, 'logistics');
        $accountant = $this->makeUser($acme, 'accountant');
        $treasurer = $this->makeUser($acme, 'accountant');
        $vehicle = Vehicle::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'plate' => 'ABC-123', 'type' => 'BUS']);
        $supplier = Supplier::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'name' => 'Llantas Sur', 'ruc' => '20111111111', 'email' => 'ventas@llantas.pe']);
        $account = CompanyBankAccount::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'bank' => 'BCP', 'account_number' => '191-1', 'currency' => 'PEN']);
        // La persona designada (gerente con firma) que autoriza pagos
        $signature = UploadedFile::fake()->image('firma.png', 200, 80);
        Signatory::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'user_id' => $manager->id, 'position' => 'Gerente general', 'can_authorize' => true,
            'signature_path' => $signature->store("finance/{$acme->id}/signatures", 'private')]);

        // RX con vehículo del maestro y serie de la sede
        $this->actingAs($requester)->post('/requisitions', [
            'requested_at' => '2026-10-23', 'requested_by' => 'Ana', 'detail' => 'Llantas bus', 'classification' => 'REPUESTOS', 'priority' => 'ALTO',
            'vehicle_id' => $vehicle->id, 'items' => [['product' => 'Llanta 295', 'unit' => 'UNIDAD (BIENES)', 'quantity' => 4]],
        ])->assertSessionHasNoErrors();
        $r = Requisition::withoutGlobalScopes()->sole();
        $this->assertSame(['ABC-123', 'RQ-TN-001-000001-' . now()->year], [$r->plate, $r->code()]);

        // ACEPTADO: aviso y correo a quien solicitó
        $this->actingAs($manager)->post("/requisitions/{$r->id}/review", ['decision' => 'approved'])->assertSessionHasNoErrors();
        Notification::assertSentTo($requester, RequisitionReviewed::class, fn ($n, $channels) => in_array('mail', $channels, true) && $n->title() === 'Requerimiento aceptado');

        // Cotización: el PDF es obligatorio (no Express) y se valida con SafeUpload
        $quote = ['supplier_id' => $supplier->id, 'amount' => 1200, 'currency' => 'PEN', 'quoted_at' => '2026-10-23'];
        $this->actingAs($logistics)->post("/requisitions/{$r->id}/quotations", $quote)->assertSessionHasErrors('file');
        $this->actingAs($logistics)->post("/requisitions/{$r->id}/quotations", $quote + ['file' => UploadedFile::fake()->create('cot.pdf', 10, 'application/pdf')])->assertSessionHasErrors('file');
        $this->actingAs($logistics)->post("/requisitions/{$r->id}/quotations", $quote + ['file' => $this->fakePdf('cot.pdf')])->assertSessionHasNoErrors();
        $q = $r->quotations()->sole();
        $this->actingAs($manager)->get("/requisitions/{$r->id}/quotations/{$q->id}/file")->assertOk();

        // OC: correo al proveedor con el PDF firmado
        $this->actingAs($logistics)->post("/requisitions/{$r->id}/order", [
            'quotation_id' => $q->id, 'type' => 'purchase', 'payment_terms' => 'CREDITO', 'payment_method' => 'TRANSFERENCIA', 'payment_origin' => 'TRANSFERENCIA',
            'priority' => 'ALTO', 'installments' => 1, 'first_due_date' => '2026-11-01', 'interval_days' => 30,
        ])->assertSessionHasNoErrors();
        $r->refresh();
        $oc = 'OC-TN-001-000001-' . now()->year;
        $this->assertSame($oc, $r->purchase_order_number);
        Notification::assertSentTo(new AnonymousNotifiable, SupplierDocument::class,
            fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'ventas@llantas.pe' && str_starts_with($n->attachment, '%PDF') && $n->attachmentName === "{$oc}.pdf");
        $this->actingAs($manager)->get("/requisitions/{$r->id}/order.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Almacén: recepción parcial y luego total, con kardex valorizado
        $item = $r->items()->sole();
        $this->actingAs($logistics)->post("/inventory/receive/{$r->id}", ['branch_id' => $branch->id, 'date' => '2026-10-23', 'items' => [['id' => $item->id, 'quantity' => 5, 'unit_cost' => 300]]])
            ->assertStatus(422); // más de lo pedido
        $this->actingAs($logistics)->post("/inventory/receive/{$r->id}", ['branch_id' => $branch->id, 'date' => '2026-10-23', 'items' => [['id' => $item->id, 'quantity' => 1, 'unit_cost' => 300]]])
            ->assertSessionHasNoErrors();
        $this->assertNull($r->fresh()->received_at);
        $this->actingAs($logistics)->post("/inventory/receive/{$r->id}", ['branch_id' => $branch->id, 'date' => '2026-10-23', 'items' => [['id' => $item->id, 'quantity' => 3, 'unit_cost' => 300]]])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($r->fresh()->received_at);
        $last = StockMovement::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame(['4.000', '1200.00', $oc, $vehicle->id], [$last->balance_quantity, $last->balance_total, $last->reference, $last->vehicle_id]);

        // OP: programada; sin la persona designada no se autoriza; desde la app sí
        $p = PaymentInstallment::withoutGlobalScopes()->sole();
        $this->actingAs($treasurer)->post("/treasury/installments/{$p->id}/schedule", ['scheduled_for' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($accountant)->post("/treasury/installments/{$p->id}/authorize")->assertForbidden();
        app(TenantContext::class)->clear();
        $this->actingIn($accountant, $acme)->getJson('/api/treasury/authorizations')->assertForbidden();
        app(TenantContext::class)->clear();
        $this->actingIn($manager, $acme)->getJson('/api/treasury/authorizations')->assertOk()->assertJsonPath('data.0.order', $oc);
        app(TenantContext::class)->clear();
        $this->actingIn($manager, $acme)->postJson("/api/treasury/authorizations/{$p->id}/authorize")->assertOk();
        $this->assertSame($manager->id, $p->fresh()->authorized_by_id);
        $this->flushHeaders(); // lo que sigue es el panel web, no la app
        app(TenantContext::class)->clear();
        $this->actingAs($treasurer)->get("/treasury/installments/{$p->id}/op.pdf")->assertOk();

        // Sin factura no se paga; con factura (PDF) sí, y el voucher es obligatorio
        $pay = ['paid_at' => now()->toDateString(), 'amount' => 1200, 'company_bank_account_id' => $account->id, 'operation_number' => 'OP-9', 'voucher' => $this->fakePdf('v.pdf')];
        $this->actingAs($treasurer)->post("/treasury/installments/{$p->id}/pay", $pay)->assertStatus(422);
        $this->actingAs($treasurer)->post("/requisitions/{$r->id}/invoice", ['invoice_number' => 'F001-77', 'invoice_date' => now()->toDateString()])->assertSessionHasErrors('file');
        $this->actingAs($treasurer)->post("/requisitions/{$r->id}/invoice", ['invoice_number' => 'F001-77', 'invoice_date' => now()->toDateString(), 'file' => $this->fakePdf('f.pdf')])
            ->assertSessionHasNoErrors();
        $this->actingAs($manager)->get("/requisitions/{$r->id}/invoice/file")->assertOk();

        $this->actingAs($treasurer)->post("/treasury/installments/{$p->id}/pay", $pay)->assertSessionHasNoErrors();
        $this->assertSame('paid', $r->fresh()->stage);
        Notification::assertSentTo(new AnonymousNotifiable, SupplierDocument::class, fn ($n) => str_starts_with($n->subject, 'Pago cancelado') && $n->attachment !== null);

        // Traza visible en el requerimiento
        $this->actingAs($manager)->get("/requisitions/{$r->id}")->assertInertia(fn ($page) => $page
            ->where('requisition.invoice.number', 'F001-77')->where('requisition.payments.0.status', 'Pago cancelado')->where('requisition.items.0.received_quantity', 4));
    }

    public function test_kardex_uses_weighted_average_cost_and_never_goes_negative(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $branch = $this->makeBranch($acme);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'name' => 'Aceite 15W40', 'unit' => 'GALON']);
        $logistics = $this->makeUser($acme, 'logistics');
        $move = fn (array $d) => $this->actingAs($logistics)->post('/inventory/movements', $d + ['product_id' => $product->id, 'branch_id' => $branch->id, 'date' => '2026-10-20']);

        $move(['type' => 'in', 'quantity' => 10, 'unit_cost' => 5])->assertSessionHasNoErrors();
        $move(['type' => 'in', 'quantity' => 10, 'unit_cost' => 7])->assertSessionHasNoErrors();
        $move(['type' => 'out', 'quantity' => 5, 'unit_cost' => null])->assertSessionHasNoErrors(); // como lo envía el panel
        $last = StockMovement::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame(['6.0000', '30.00', '15.000', '90.00'], [$last->unit_cost, $last->total_cost, $last->balance_quantity, $last->balance_total]);

        $move(['type' => 'out', 'quantity' => 16])->assertSessionHasErrors('quantity');
        $move(['type' => 'out', 'quantity' => 15])->assertSessionHasNoErrors();
        $this->assertSame(['0.000', '0.00'], [StockMovement::withoutGlobalScopes()->latest('id')->first()->balance_quantity, StockMovement::withoutGlobalScopes()->latest('id')->first()->balance_total]);

        $this->actingAs($logistics)->get("/inventory?product={$product->id}&branch={$branch->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->has('kardex', 4)->has('stock', 1));
        $this->actingAs($this->makeUser($acme, 'supervisor'))->get('/inventory')->assertForbidden();
    }

    public function test_fuel_efficiency_by_plate_and_odometer_cannot_go_back(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $logistics = $this->makeUser($acme, 'logistics');
        $this->actingAs($logistics)->post('/vehicles', ['plate' => 'AB#1', 'type' => 'BUS'])->assertSessionHasErrors('plate');
        $this->actingAs($logistics)->post('/vehicles', ['plate' => 'abc-123', 'type' => 'BUS', 'brand' => 'Volvo'])->assertSessionHasNoErrors();
        $this->actingAs($logistics)->post('/vehicles', ['plate' => 'ABC-123', 'type' => 'AUTO'])->assertSessionHasErrors('plate');
        $vehicle = Vehicle::withoutGlobalScopes()->sole();
        $this->assertSame('ABC-123', $vehicle->plate);

        $load = fn (array $d) => $this->actingAs($logistics)->post('/fuel', $d + ['vehicle_id' => $vehicle->id, 'fuel_type' => 'DIESEL', 'price_per_gallon' => 15.5]);
        $load(['date' => '2026-10-01', 'gallons' => 50, 'odometer' => 10000])->assertSessionHasNoErrors();
        $load(['date' => '2026-10-05', 'gallons' => 40, 'odometer' => 9000])->assertSessionHasErrors('odometer');
        $load(['date' => '2026-10-05', 'gallons' => 40, 'odometer' => 10600])->assertSessionHasNoErrors();
        $this->assertSame('620.00', FuelLoad::withoutGlobalScopes()->latest('id')->first()->total);

        $this->actingAs($logistics)->get('/fuel?month=2026-10')->assertInertia(fn ($p) => $p
            ->where('loads.0.km', 600)->where('loads.0.km_per_gallon', 15)->where('by_vehicle.ABC-123.km_per_gallon', 15));

        // Con historial no se borra el vehículo
        $this->actingAs($logistics)->delete("/vehicles/{$vehicle->id}")->assertStatus(422);
    }

    public function test_the_plate_of_a_deleted_vehicle_reactivates_it_instead_of_failing(): void
    {
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $logistics = $this->makeUser($acme, 'logistics');
        $this->actingAs($logistics)->post('/vehicles', ['plate' => 'XYZ-987', 'type' => 'BUS', 'brand' => 'Volvo'])->assertSessionHasNoErrors();
        $old = Vehicle::withoutGlobalScopes()->sole();
        $this->delete("/vehicles/{$old->id}")->assertSessionHasNoErrors(); // sin cargas: borrado lógico

        // Antes: 500 por el índice único (tenant_id, plate). Ahora se reactiva el mismo
        $this->post('/vehicles', ['plate' => 'xyz-987', 'type' => 'AUTO', 'brand' => 'Toyota'])->assertSessionHasNoErrors();
        $vehicle = Vehicle::withoutGlobalScopes()->sole();
        $this->assertSame([$old->id, 'AUTO', 'Toyota', null], [$vehicle->id, $vehicle->type, $vehicle->brand, $vehicle->deleted_at]);

        // Editar otro con la placa de uno eliminado: mensaje claro, no 500
        $other = Vehicle::withoutGlobalScopes()->create(['tenant_id' => $acme->id, 'plate' => 'OTR-111', 'type' => 'BUS']);
        $this->delete("/vehicles/{$vehicle->id}");
        $this->put("/vehicles/{$other->id}", ['plate' => 'XYZ-987', 'type' => 'BUS'])->assertSessionHasErrors('plate');
    }

    public function test_signatories_are_managed_by_admin_and_signature_is_private_image(): void
    {
        Storage::fake('private');
        $this->seedRoles();
        $acme = $this->makeTenant('acme');
        $other = $this->makeTenant('other');
        $admin = $this->makeUser($acme, 'company_admin');
        $manager = $this->makeUser($acme, 'manager');
        $alien = $this->makeUser($other, 'employee');

        $this->actingAs($this->makeUser($acme, 'accountant'))->get('/signatories')->assertForbidden();
        $this->actingAs($admin)->post('/signatories', ['user_id' => $alien->id, 'position' => 'X'])->assertSessionHasErrors('user_id');
        $this->actingAs($admin)->post('/signatories', ['user_id' => $manager->id, 'position' => 'Gerente', 'signature' => $this->fakePdf('f.pdf')])->assertSessionHasErrors('signature');
        $this->actingAs($admin)->post('/signatories', ['user_id' => $manager->id, 'position' => 'Gerente', 'can_authorize' => 1, 'signature' => UploadedFile::fake()->image('f.png')])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/signatories', ['user_id' => $manager->id, 'position' => 'Otra vez'])->assertSessionHasErrors('user_id');

        $s = Signatory::withoutGlobalScopes()->sole();
        $this->assertTrue($s->can_authorize);
        Storage::disk('private')->assertExists($s->signature_path);
        $this->actingAs($admin)->get("/signatories/{$s->id}/signature")->assertOk();
        $this->actingAs($admin)->post("/signatories/{$s->id}", ['position' => 'Gerente', 'can_authorize' => 0, 'is_active' => 1])->assertSessionHasNoErrors();
        $this->assertFalse(Signatory::canAuthorize($manager));
    }
}
