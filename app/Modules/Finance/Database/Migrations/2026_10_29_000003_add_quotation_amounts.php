<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cotizaciones comparables: monto, moneda y cuál se eligió para la orden.
 * Y quién aprobó/rechazó el requerimiento (usuario, no solo el nombre).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->decimal('amount', 14, 2)->nullable()->after('number');
            $table->string('currency', 10)->default('PEN')->after('amount');
            $table->string('notes', 250)->nullable()->after('phone');
            $table->boolean('is_selected')->default(false)->after('notes');
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->foreignId('reviewed_by_id')->nullable()->after('reviewed_by')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_id');
            $table->timestamp('ordered_at')->nullable()->after('service_order_number');
        });
    }

    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by_id');
            $table->dropColumn(['reviewed_at', 'ordered_at']);
        });
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['amount', 'currency', 'notes', 'is_selected']);
        });
    }
};
