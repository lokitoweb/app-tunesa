<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pago de cada cuota de costo fijo: fecha, cuenta de origen y N.º de operación */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_costs', function (Blueprint $table) {
            $table->date('paid_at')->nullable()->after('is_active');
            $table->string('operation_number', 50)->nullable()->after('paid_at');
            $table->foreignId('company_bank_account_id')->nullable()->after('operation_number')->constrained()->nullOnDelete();
            $table->index(['tenant_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('fixed_costs', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'date']);
            $table->dropConstrainedForeignId('company_bank_account_id');
            $table->dropColumn(['paid_at', 'operation_number']);
        });
    }
};
