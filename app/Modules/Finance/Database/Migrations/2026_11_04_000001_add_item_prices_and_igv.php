<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Precios por ítem y desglose de IGV de la OC/OS (sisgere: tbldetalle.precio_unit, descuento,
 * sub_total; botones Igv / S/Igv de FrmReq). Todo opcional: una orden sin precios por ítem sigue
 * tomando el total de la cotización, como hasta ahora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->decimal('unit_price', 14, 4)->nullable()->after('quantity');
            $table->decimal('discount_percent', 5, 2)->default(0)->after('unit_price');
            $table->decimal('subtotal', 14, 2)->nullable()->after('discount_percent');
        });

        Schema::table('requisitions', function (Blueprint $table) {
            // ¿Los precios de los ítems ya incluyen el IGV? (en sisgere: Igv = ×(1+tasa), S/Igv = ÷(1+tasa))
            $table->boolean('prices_include_igv')->nullable()->after('amount_payable');
            // Tasa con la que se emitió la orden: una orden antigua conserva la suya si la tasa cambia
            $table->decimal('igv_rate', 5, 4)->nullable()->after('prices_include_igv');
            $table->decimal('taxable_amount', 14, 2)->nullable()->after('igv_rate'); // valor venta
            $table->decimal('igv_amount', 14, 2)->nullable()->after('taxable_amount');
        });
    }

    public function down(): void
    {
        Schema::table('requisition_items', fn (Blueprint $table) => $table->dropColumn(['unit_price', 'discount_percent', 'subtotal']));
        Schema::table('requisitions', fn (Blueprint $table) => $table->dropColumn(['prices_include_igv', 'igv_rate', 'taxable_amount', 'igv_amount']));
    }
};
