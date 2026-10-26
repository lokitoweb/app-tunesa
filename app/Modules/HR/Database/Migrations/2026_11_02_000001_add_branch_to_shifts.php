<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada horario pertenece a una agencia (sede): en la programación por calendario solo se
 * ofrecen los horarios de la agencia elegida. Los horarios que ya existían quedan sin
 * agencia (NULL = disponibles en todas) para no romper nada; al editarlos se pide la agencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
