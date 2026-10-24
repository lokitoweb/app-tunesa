<?php

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maestros y logística (traza RX -> Cotización -> OC/OS -> Almacén -> OP -> Factura -> Firma -> Voucher):
 * - branches.document_series: serie de la sede en los correlativos (001, SED-01).
 * - vehicles: flota de la empresa (placa única por empresa).
 * - signatories: apoderados con firma escaneada (privada) que se estampa en OC/OS/OP;
 *   los marcados can_authorize son "la persona designada" que autoriza pagos.
 * - quotations.file_path, requisitions.invoice_*: PDF de cotización y de factura.
 * - stock_movements: kardex valorizado (costo promedio ponderado) por producto y almacén (sede).
 * - requisition_items.received_quantity: recepción de la OC en almacén.
 * - fuel_loads: cargas de combustible por vehículo con kilometraje.
 */
return new class extends Migration
{
    public const PERMISSIONS = ['fleet.manage', 'signatories.manage', 'inventory.manage', 'fuel.manage'];

    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('document_series', 20)->nullable()->comment('Serie en RQ/OC/OS');
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('plate', 15);
            $table->string('brand', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->string('type', 20)->comment('BUS | CAMIONETA | AUTO | OTRO');
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'plate']);
        });

        Schema::create('signatories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('position', 100);
            $table->string('signature_path')->nullable();
            $table->boolean('can_authorize')->default(false)->comment('Autoriza órdenes de pago');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->string('file_path')->nullable();
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->string('invoice_path')->nullable()->after('invoice_number');
            $table->date('invoice_date')->nullable()->after('invoice_path');
            $table->foreignId('vehicle_id')->nullable()->after('plate')->constrained()->nullOnDelete();
            $table->timestamp('received_at')->nullable()->after('ordered_at');
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->decimal('received_quantity', 14, 3)->default(0);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete()->comment('Almacén = sede');
            $table->date('date');
            $table->string('type', 10)->comment('in | out');
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 4);
            $table->decimal('total_cost', 14, 2);
            $table->decimal('balance_quantity', 14, 3);
            $table->decimal('balance_unit_cost', 14, 4)->comment('Costo promedio ponderado tras el movimiento');
            $table->decimal('balance_total', 14, 2);
            $table->foreignId('requisition_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 100)->nullable();
            $table->string('notes', 250)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'product_id', 'branch_id', 'id']);
        });

        Schema::create('fuel_loads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('gallons', 10, 3);
            $table->decimal('price_per_gallon', 10, 4);
            $table->decimal('total', 12, 2);
            $table->unsignedInteger('odometer')->comment('Kilometraje al cargar');
            $table->string('fuel_type', 20)->default('DIESEL');
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_number', 50)->nullable();
            $table->string('driver', 150)->nullable();
            $table->foreignId('requisition_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'vehicle_id', 'date']);
        });

        $ids = [];
        foreach (self::PERMISSIONS as $slug) {
            [$name, $group] = RolesAndPermissionsSeeder::PERMISSIONS[$slug];
            $ids[$slug] = Permission::updateOrCreate(['slug' => $slug], ['name' => $name, 'group' => $group])->id;
        }
        foreach (RolesAndPermissionsSeeder::ROLES as $roleSlug => [, , $grants]) {
            $grant = array_values(array_intersect_key($ids, array_flip(RolesAndPermissionsSeeder::expand($grants))));
            Role::whereNull('tenant_id')->where('slug', $roleSlug)->first()?->permissions()->syncWithoutDetaching($grant);
        }
    }

    public function down(): void
    {
        Permission::whereIn('slug', self::PERMISSIONS)->get()->each(function (Permission $p) {
            $p->roles()->detach();
            $p->delete();
        });
        Schema::dropIfExists('fuel_loads');
        Schema::dropIfExists('stock_movements');
        Schema::table('requisition_items', fn (Blueprint $t) => $t->dropColumn('received_quantity'));
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vehicle_id');
            $table->dropColumn(['invoice_path', 'invoice_date', 'received_at']);
        });
        Schema::table('quotations', fn (Blueprint $t) => $t->dropColumn('file_path'));
        Schema::dropIfExists('signatories');
        Schema::dropIfExists('vehicles');
        Schema::table('branches', fn (Blueprint $t) => $t->dropColumn('document_series'));
    }
};
