<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requerimiento Express (gasto menor a S/ 600): lo valida la jefatura inmediata o
 * contabilidad (sin aprobación gerencial) y se ordena con un solo proveedor, sin
 * cuadro comparativo. 'amount' guarda el monto estimado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->boolean('is_express')->default(false)->after('stage');
        });
    }

    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropColumn('is_express');
        });
    }
};
