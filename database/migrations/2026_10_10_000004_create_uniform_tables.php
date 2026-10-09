<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dotación de indumentaria: tallas vigentes por colaborador (uniform_sizes)
 * e historial de entregas (uniform_deliveries).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uniform_sizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('shirt_size', 10)->nullable()->comment('Polo / camisa: XS..XXXL');
            $table->string('pants_size', 10)->nullable()->comment('Pantalón: 26..48 o XS..XXXL');
            $table->string('shoe_size', 10)->nullable()->comment('Calzado: 34..47');
            $table->enum('shoe_type', ['safety', 'formal', 'sneaker'])->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('uniform_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('item', ['shirt', 'pants', 'shoes', 'jacket', 'helmet', 'vest', 'other']);
            $table->string('size', 10)->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->date('delivered_at');
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'delivered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uniform_deliveries');
        Schema::dropIfExists('uniform_sizes');
    }
};
