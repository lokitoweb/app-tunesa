<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Descuento en monto sobre el total de la OC/OS (sisgere FrmReq: Txtdescuento, guardado como
 * "descuento" en requerimiento_req17062026). Se resta del total antes de la detracción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->decimal('discount_amount', 14, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropColumn('discount_amount');
        });
    }
};
