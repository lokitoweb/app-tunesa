<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motivo de detracción de la OC/OS (sisgere FrmReq: combo de Tdetraccion, que trae el %).
 * Las órdenes anteriores quedan sin motivo y Tesorería registra su detracción como hasta ahora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->string('detraction_reason', 3)->nullable()->after('has_detraction'); // código SUNAT
        });
    }

    public function down(): void
    {
        Schema::table('requisitions', fn (Blueprint $table) => $table->dropColumn('detraction_reason'));
    }
};
