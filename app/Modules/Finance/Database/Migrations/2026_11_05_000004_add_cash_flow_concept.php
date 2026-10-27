<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Concepto del flujo de efectivo de cada orden o pago (sisgere FLUJOEFECT_requerimiento01) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->string('cash_flow_concept', 150)->nullable()->after('classification');
        });
    }

    public function down(): void
    {
        Schema::table('requisitions', fn (Blueprint $table) => $table->dropColumn('cash_flow_concept'));
    }
};
