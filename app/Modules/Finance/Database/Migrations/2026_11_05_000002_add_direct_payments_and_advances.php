<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pago directo de Tesorería (sisgere FrmReqProg › Registrar: PG-…, sin pasar por logística) y
 * anticipos a proveedores (Tcuotadoc.anticipo / referencia): un anticipo pagado se descuenta de la
 * siguiente OC/OS del mismo proveedor (FrmReq › Aplicar anticipo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->boolean('is_direct_payment')->default(false)->after('payroll_concept');
            // Anticipos aplicados a esta orden: se restan del neto antes de armar las cuotas
            $table->decimal('advance_amount', 14, 2)->nullable()->after('amount_payable');
        });

        Schema::table('payment_installments', function (Blueprint $table) {
            $table->boolean('is_advance')->default(false)->after('status');
            // Orden a la que se aplicó el anticipo (sisgere: referencia); null = disponible
            $table->foreignId('advance_applied_to_id')->nullable()->after('is_advance')->constrained('requisitions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_installments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('advance_applied_to_id');
            $table->dropColumn('is_advance');
        });
        Schema::table('requisitions', fn (Blueprint $table) => $table->dropColumn(['is_direct_payment', 'advance_amount']));
    }
};
