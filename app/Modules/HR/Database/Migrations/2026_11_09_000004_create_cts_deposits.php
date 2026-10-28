<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Depósitos semestrales de CTS (mayo y noviembre), tal como se calcularon, con su constancia */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cts_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');   // 1 de noviembre o 1 de mayo
            $table->date('period_end');     // 30 de abril o 31 de octubre
            $table->date('due_date');       // 15 de mayo o 15 de noviembre
            $table->json('data');
            $table->decimal('amount', 12, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cts_deposits');
    }
};
