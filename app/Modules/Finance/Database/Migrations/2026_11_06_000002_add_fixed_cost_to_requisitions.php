<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Costo fijo → Tesorería (sisgere FrmReqProg: cada cuota se volvía una ODP que se programaba,
 * autorizaba y pagaba con voucher). La cuota se envía como pago directo PG-…; este enlace dice de
 * qué cuota viene, y al pagarse la cuota queda pagada sola. Una cuota, un solo pago (unique).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->foreignId('fixed_cost_id')->nullable()->after('employee_id')->unique()->constrained('fixed_costs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fixed_cost_id');
        });
    }
};
