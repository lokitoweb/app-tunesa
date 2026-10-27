<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitud de cotización a proveedores (sisgere FrmReq: CREAR_SOLCOTIZACION, reporte CRsolcoti):
 * un SC-… por proveedor, con las condiciones que se le piden. Se envía en PDF por correo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requisition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('code', 60);
            $table->timestamp('emailed_at')->nullable();
            $table->string('sent_by', 150)->nullable();
            $table->timestamps();
            $table->unique(['requisition_id', 'supplier_id']);
        });

        Schema::table('requisitions', function (Blueprint $table) {
            // Condiciones que se piden a los proveedores (ACTUALIZAR_SOLICITUD)
            $table->string('quote_payment_terms', 100)->nullable()->after('delivery_place');
            $table->string('delivery_mode', 10)->nullable()->after('quote_payment_terms'); // pickup | delivery
            $table->string('quote_notes', 300)->nullable()->after('delivery_mode');
        });
    }

    public function down(): void
    {
        Schema::table('requisitions', fn (Blueprint $table) => $table->dropColumn(['quote_payment_terms', 'delivery_mode', 'quote_notes']));
        Schema::dropIfExists('quote_requests');
    }
};
